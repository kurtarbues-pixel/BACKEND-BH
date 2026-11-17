<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInquiryRequest;
use App\Http\Resources\InquiryResource;
use App\Http\Resources\LeaseResource;
use App\Models\Inquiry;
use App\Models\Notification;
use App\Models\Room;
use App\Services\ActivityLogger;
use App\Services\InquiryApprovalService;
use App\Services\RoomAvailabilityService;
use App\Services\TenantActiveRental;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class InquiryController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Inquiry::query()->with(['room.images', 'room.landlord.role', 'tenant.role']);

        if ($user->isTenant()) {
            $query->where('tenant_id', $user->id);
        } elseif ($user->isLandlord()) {
            $query->whereHas('room', fn ($q) => $q->where('landlord_id', $user->id));
        } elseif (! $user->isAdmin()) {
            abort(403);
        }

        return InquiryResource::collection($query->latest()->paginate(20));
    }

    public function store(StoreInquiryRequest $request, Room $room, RoomAvailabilityService $availability, TenantActiveRental $tenantRental)
    {
        if ($room->status !== 'available') {
            throw ValidationException::withMessages([
                'room' => 'This room is not available.',
            ]);
        }

        $existing = Inquiry::where('room_id', $room->id)
            ->where('tenant_id', $request->user()->id)
            ->whereIn('status', [Inquiry::PENDING, Inquiry::APPROVED])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'room' => 'You already have a pending inquiry for this room.',
            ]);
        }

        $inquiry = \Illuminate\Support\Facades\DB::transaction(function () use ($room, $request, $availability, $tenantRental) {
            $tenantRental->lockTenant($request->user()->id);
            $tenantRental->assertNoActiveLease($request->user()->id);
            $lockedRoom = Room::whereKey($room->id)->lockForUpdate()->firstOrFail();
            if ($lockedRoom->status !== 'available') {
                throw ValidationException::withMessages(['room' => 'This room is no longer available.']);
            }
            if (Inquiry::where('room_id', $lockedRoom->id)->where('tenant_id', $request->user()->id)
                ->whereIn('status', [Inquiry::PENDING, Inquiry::APPROVED])->exists()) {
                throw ValidationException::withMessages(['room' => 'You already have an active application for this room.']);
            }

            $data = $request->validated();
            $availability->assertAvailable(
                $lockedRoom,
                $data['desired_start_date'] ?? BusinessTime::date(),
                $data['desired_end_date'] ?? null,
            );

            return Inquiry::create([
                'room_id' => $lockedRoom->id,
                'tenant_id' => $request->user()->id,
                'message' => $data['message'],
                'desired_start_date' => $data['desired_start_date'] ?? null,
                'desired_end_date' => $data['desired_end_date'] ?? null,
                'status' => Inquiry::PENDING,
            ]);
        });

        ActivityLogger::log('inquiry.created', $inquiry);

        // Notify landlord of incoming rental application
        $responsibleLandlordId = $inquiry->room?->landlord_id;
        if ($responsibleLandlordId) {
            Notification::create([
                'user_id' => $responsibleLandlordId,
                'type' => 'inquiry.new',
                'title' => 'New Room Application',
                'body' => $request->user()->name.' submitted an application for Room '.$room->room_number.'.',
                'data' => [
                    'inquiry_id' => $inquiry->id,
                    'room_id' => $room->id,
                    'room_number' => $room->room_number,
                    'room_title' => $room->title,
                    'applicant_name' => $request->user()->name,
                    'applicant_email' => $request->user()->email,
                    'applicant_phone' => $request->user()->phone,
                    'message' => $inquiry->message,
                    'monthly_rent' => $room->price,
                ],
            ]);
        }
        return (new InquiryResource($inquiry->load(['room.images', 'tenant.role'])))
            ->response()
            ->setStatusCode(201);
    }

    public function approve(Request $request, Inquiry $inquiry, InquiryApprovalService $service)
    {
        $this->authorize('approve', $inquiry);

        try {
            $inquiry = $service->approve($inquiry, $request->validate([
                'reason' => ['nullable', 'string', 'max:2000'],
            ])['reason'] ?? null);
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'room_taken'], 409);
        }

        return response()->json(['message' => 'Rental offer sent. The tenant has 24 hours to respond.', 'inquiry' => new InquiryResource($inquiry->load(['room.images', 'tenant.role']))]);
    }

    public function reject(Request $request, Inquiry $inquiry, InquiryApprovalService $service)
    {
        $this->authorize('reject', $inquiry);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:2000']])['reason'] ?? null;
        $inquiry = $service->reject($inquiry, $reason);

        return new InquiryResource($inquiry->load(['room.images', 'tenant.role']));
    }

    public function accept(Request $request, Inquiry $inquiry, InquiryApprovalService $service)
    {
        abort_unless($request->user()->isTenant() && $inquiry->tenant_id === $request->user()->id, 404);
        try {
            $lease = $service->accept($inquiry, $request->ip());
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'offer_unavailable'], 409);
        }

        return response()->json([
            'message' => 'Rental offer and digital lease agreement accepted. Complete the move-in inspection with your landlord.',
            'lease' => new LeaseResource($lease),
            'inquiry' => new InquiryResource($inquiry->fresh(['room.images', 'tenant.role'])),
        ]);
    }

    public function decline(Request $request, Inquiry $inquiry, InquiryApprovalService $service)
    {
        abort_unless($request->user()->isTenant() && $inquiry->tenant_id === $request->user()->id, 404);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:2000']])['reason'] ?? null;

        return new InquiryResource($service->declineOffer($inquiry, $reason)->load(['room.images', 'tenant.role']));
    }
}
