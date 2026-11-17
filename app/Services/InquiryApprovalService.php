<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Lease;
use App\Models\Notification;
use App\Support\BusinessTime;
use App\Services\TenantActiveRental;
use App\Models\Room;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\RoomAvailabilityService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InquiryApprovalService
{
    public function approve(Inquiry $inquiry, ?string $reason = null): Inquiry
    {
        $tenantId = (int) Inquiry::whereKey($inquiry->id)->value('tenant_id');

        return DB::transaction(function () use ($inquiry, $reason, $tenantId) {
            $tenantRental = app(TenantActiveRental::class);
            $tenantRental->lockTenant($tenantId);
            $tenantRental->assertNoActiveLease($tenantId);
            $inquiry = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();

            if (! $inquiry->canTransitionTo(Inquiry::APPROVED)) {
                throw ValidationException::withMessages([
                    'status' => 'This inquiry cannot be approved from its current state.',
                ]);
            }

            $room = Room::whereKey($inquiry->room_id)->lockForUpdate()->firstOrFail();

            $taken = Lease::where('active_room_id', $room->id)->exists();
            $held = Inquiry::where('room_id', $room->id)
                ->where('status', Inquiry::APPROVED)
                ->where('offer_expires_at', '>', now())
                ->where('id', '!=', $inquiry->id)
                ->exists();
            if ($taken || $held || $room->status === 'occupied') {
                throw new ConflictHttpException('This room was just taken or offered to another applicant.');
            }

            if ($room->status !== 'available') {
                throw new ConflictHttpException('This room is not available for a new offer.');
            }

            app(RoomAvailabilityService::class)->assertAvailable(
                $room,
                $inquiry->desired_start_date?->toDateString() ?? BusinessTime::date(),
                $inquiry->desired_end_date?->toDateString(),
            );

            $otherPending = Inquiry::where('room_id', $room->id)
                ->where('id', '!=', $inquiry->id)
                ->where('status', Inquiry::PENDING)
                ->exists();
            if ($otherPending) {
                throw ValidationException::withMessages(['applicants' => 'Review and decline other applicants before making an offer. This keeps the first-qualified applicant decision explicit.']);
            }

            $inquiry->update([
                'status' => Inquiry::APPROVED,
                'offer_expires_at' => now()->addHours(24),
                'decision_reason' => $reason,
            ]);
            $room->update(['status' => 'reserved']);

            // Notify tenant of the approved rental offer with 24 hours to accept
            Notification::create([
                'user_id' => $inquiry->tenant_id,
                'type' => 'inquiry.offer',
                'title' => 'Rental Offer Received!',
                'body' => 'Your application for Room '.$room->room_number.' ('.$room->title.') has been approved! You have 24 hours to accept.',
                'data' => [
                    'inquiry_id' => $inquiry->id,
                    'room_id' => $room->id,
                    'room_number' => $room->room_number,
                    'room_title' => $room->title,
                    'offer_expires_at' => $inquiry->offer_expires_at?->toIso8601String(),
                    'monthly_rent' => $room->price,
                ],
            ]);

            return $inquiry->fresh(['room', 'tenant']);
        });
    }

    public function accept(Inquiry $inquiry, ?string $acceptedIp = null): Lease
    {
        $tenantId = (int) Inquiry::whereKey($inquiry->id)->value('tenant_id');
        $lease = DB::transaction(function () use ($inquiry, $acceptedIp, $tenantId) {
            $tenantRental = app(TenantActiveRental::class);
            $tenantRental->lockTenant($tenantId);
            $tenantRental->assertNoActiveLease($tenantId);
            $inquiry = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $room = Room::whereKey($inquiry->room_id)->lockForUpdate()->firstOrFail();

            if ($inquiry->status !== Inquiry::APPROVED || ! $inquiry->offer_expires_at || $inquiry->offer_expires_at->isPast()) {
                if ($inquiry->status === Inquiry::APPROVED) {
                    $inquiry->update(['status' => Inquiry::REJECTED, 'decision_reason' => 'The rental offer expired.']);
                    if (! Lease::where('active_room_id', $room->id)->exists()) {
                        $hasOtherOffer = Inquiry::where('room_id', $room->id)->where('id', '!=', $inquiry->id)
                            ->where('status', Inquiry::APPROVED)->where('offer_expires_at', '>', now())->exists();
                        $room->update(['status' => $hasOtherOffer ? 'reserved' : 'available']);
                    }
                }
                throw new ConflictHttpException('This rental offer has expired or is no longer available.');
            }

            if (Lease::where('active_room_id', $room->id)->exists()) {
                throw new ConflictHttpException('This room was just taken.');
            }

            $startDate = $inquiry->desired_start_date?->toDateString() ?? BusinessTime::date();
            $endDate = $inquiry->desired_end_date?->toDateString();
            app(RoomAvailabilityService::class)->assertAvailable($room, $startDate, $endDate, $inquiry->id);

            $lease = Lease::create([
                'room_id' => $room->id,
                'tenant_id' => $inquiry->tenant_id,
                'landlord_id' => $room->landlord_id,
                'inquiry_id' => $inquiry->id,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'monthly_rent' => $room->price,
                'status' => 'active',
                'active_room_id' => $room->id,
                'agreement_status' => 'pending',
                'agreement_accepted_at' => null,
                'agreement_accepted_ip' => null,
                'agreement_snapshot' => [
                    'landlord_name' => $room->landlord?->name ?? 'Landlord',
                    'tenant_name' => $inquiry->tenant?->name ?? 'Tenant',
                    'room_number' => $room->room_number,
                    'room_title' => $room->title,
                    'location' => $room->location,
                    'monthly_rent' => (string) $room->price,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
            ]);

            $room->update(['status' => 'occupied']);
            $inquiry->update(['status' => Inquiry::CONVERTED]);

            $inquiry = $inquiry->fresh(['tenant', 'room']);

            // Notify landlord that the agreement was accepted; move-in is recorded separately.
            if ($room->landlord_id) {
                Notification::create([
                    'user_id' => $room->landlord_id,
                    'type' => 'lease.started',
                    'title' => 'Lease Agreement Accepted',
                    'body' => $inquiry->tenant?->name.' accepted the agreement for Room '.$room->room_number.'. Record the move-in inspection to complete occupancy.',
                    'data' => [
                        'lease_id' => $lease->id,
                        'room_id' => $room->id,
                        'room_number' => $room->room_number,
                        'tenant_name' => $inquiry->tenant?->name,
                    ],
                ]);
            }

            return $lease->fresh(['room', 'tenant', 'landlord']);
        });

        $inquiry = $inquiry->fresh(['tenant', 'room']);

        Notification::create([
            'user_id' => $inquiry->tenant_id,
            'type' => 'lease.agreement_accepted',
            'title' => 'Lease Agreement Accepted',
            'body' => 'Your agreement for Room '.$inquiry->room?->room_number.' ('.$inquiry->room?->title.') is signed. Arrange the move-in inspection with your landlord.',
            'data' => [
                'lease_id' => $lease->id,
                'room_id' => $inquiry->room_id,
                'room_number' => $inquiry->room?->room_number,
            ],
        ]);

            ActivityLogger::log('inquiry.offer_accepted', $inquiry, ['lease_id' => $lease->id]);

        return $lease;
    }

    public function reject(Inquiry $inquiry, ?string $reason = null): Inquiry
    {
        return DB::transaction(function () use ($inquiry, $reason) {
            $inquiry = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            if (! $inquiry->canTransitionTo(Inquiry::REJECTED)) {
                throw ValidationException::withMessages(['status' => 'This inquiry cannot be rejected from its current state.']);
            }

            $inquiry->update(['status' => Inquiry::REJECTED, 'decision_reason' => $reason]);
            ActivityLogger::log('inquiry.rejected', $inquiry);

            Notification::create([
                'user_id' => $inquiry->tenant_id,
                'type' => 'inquiry.rejected',
                'title' => 'Application Update',
                'body' => 'Your application for Room '.$inquiry->room?->room_number.' was not approved.'.($reason ? " Reason: {$reason}" : ''),
                'data' => [
                    'inquiry_id' => $inquiry->id,
                    'room_id' => $inquiry->room_id,
                    'room_number' => $inquiry->room?->room_number,
                    'room_title' => $inquiry->room?->title,
                    'reason' => $reason,
                ],
            ]);

            return $inquiry->fresh();
        });
    }

    public function declineOffer(Inquiry $inquiry, ?string $reason = null): Inquiry
    {
        return DB::transaction(function () use ($inquiry, $reason) {
            $inquiry = Inquiry::whereKey($inquiry->id)->lockForUpdate()->firstOrFail();
            $room = Room::whereKey($inquiry->room_id)->lockForUpdate()->firstOrFail();
            if ($inquiry->status !== Inquiry::APPROVED || ! $inquiry->offer_expires_at || $inquiry->offer_expires_at->isPast()) {
                throw new ConflictHttpException('There is no pending offer for this request.');
            }

            $inquiry->update(['status' => Inquiry::REJECTED, 'decision_reason' => $reason]);
            if (! Lease::where('active_room_id', $room->id)->exists()) {
                $hasOtherOffer = Inquiry::where('room_id', $room->id)->where('id', '!=', $inquiry->id)
                    ->where('status', Inquiry::APPROVED)->where('offer_expires_at', '>', now())->exists();
                $room->update(['status' => $hasOtherOffer ? 'reserved' : 'available']);
            }

            // Notify landlord that offer was declined
            if ($room->landlord_id) {
                Notification::create([
                    'user_id' => $room->landlord_id,
                    'type' => 'inquiry.declined',
                    'title' => 'Rental Offer Declined',
                    'body' => ($inquiry->tenant?->name ?? 'Applicant').' declined the offer for Room '.$room->room_number.'. The room is now available.',
                    'data' => [
                        'inquiry_id' => $inquiry->id,
                        'room_id' => $room->id,
                        'room_number' => $room->room_number,
                    ],
                ]);
            }

            ActivityLogger::log('inquiry.offer_declined', $inquiry);
            return $inquiry->fresh();
        });
    }
}
