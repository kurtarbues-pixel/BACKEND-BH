<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreChargeRequest;
use App\Http\Requests\StoreLeaseRequest;
use App\Http\Requests\UpdateLeaseRequest;
use App\Http\Resources\LeaseResource;
use App\Models\Lease;
use App\Models\PendingCharge;
use App\Models\Room;
use App\Models\User;
use App\Models\LeaseInspection;
use App\Models\Invoice;
use App\Services\ActivityLogger;
use App\Services\RoomAvailabilityService;
use App\Services\LeaseAgreementPdf;
use App\Services\TenantActiveRental;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaseController extends Controller
{
    public function store(StoreLeaseRequest $request, RoomAvailabilityService $availability, TenantActiveRental $tenantRental)
    {
        $data = $request->validated();
        $lease = DB::transaction(function () use ($request, $data, $availability, $tenantRental) {
            $tenantRental->lockTenant((int) $data['tenant_id']);
            $tenantRental->assertNoActiveLease((int) $data['tenant_id']);
            $room = Room::query()->lockForUpdate()->findOrFail($data['room_id']);
            $tenant = User::with('role')->findOrFail($data['tenant_id']);

            if (! $tenant->isTenant() || ! $tenant->is_active) {
                throw ValidationException::withMessages(['tenant_id' => 'Choose an active tenant account.']);
            }

            if (! $request->user()->isAdmin() && $room->landlord_id !== $request->user()->id) {
                abort(403);
            }

            if (Lease::where('active_room_id', $room->id)->exists()
                || \App\Models\Inquiry::where('room_id', $room->id)->where('status', 'approved')->where('offer_expires_at', '>', now())->exists()) {
                throw ValidationException::withMessages(['room_id' => 'This room is not currently available.']);
            }

            $startDate = $data['start_date'];
            $endDate = $data['end_date'] ?? null;
            $availability->assertAvailable($room, $startDate, $endDate);

            $lease = Lease::create([
                'room_id' => $room->id,
                'tenant_id' => $tenant->id,
                'landlord_id' => $room->landlord_id,
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'] ?? null,
                'monthly_rent' => $data['monthly_rent'] ?? $room->price,
                'status' => 'active',
                'active_room_id' => $room->id,
                'agreement_status' => 'pending',
            ]);
            $room->update(['status' => 'occupied']);

            return $lease->load(['room.images', 'room.landlord', 'tenant.role', 'landlord.role', 'charges']);
        });

        ActivityLogger::log('lease.created', $lease);

        return (new LeaseResource($lease))->response()->setStatusCode(201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Lease::query()->with(['room.images', 'room.landlord', 'tenant.role', 'landlord.role', 'charges', 'inspections']);

        if ($user->isTenant()) {
            $query->where('tenant_id', $user->id);
        } elseif ($user->isLandlord()) {
            $query->where('landlord_id', $user->id);
        } elseif (! $user->isAdmin()) {
            abort(403);
        }

        return LeaseResource::collection($query->latest()->paginate(20));
    }

    public function update(UpdateLeaseRequest $request, Lease $lease, RoomAvailabilityService $availability)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $lease), 403);

        $data = $request->validated();
        $termsChanged = collect(['start_date', 'end_date', 'monthly_rent'])
            ->contains(fn ($field) => array_key_exists($field, $data) && (string) $data[$field] !== (string) $lease->{$field});
        if ($termsChanged && $lease->moveInInspection()->exists()) {
            throw ValidationException::withMessages(['lease' => 'Lease terms cannot be changed after move-in has been recorded.']);
        }
        $availability->assertAvailable(
            $lease->room,
            $data['start_date'] ?? $lease->start_date->toDateString(),
            array_key_exists('end_date', $data) ? $data['end_date'] : $lease->end_date?->toDateString(),
            null,
            $lease->id,
        );
        if ($termsChanged && $lease->agreement_status === 'accepted') {
            $data['agreement_status'] = 'pending';
            $data['agreement_accepted_at'] = null;
            $data['agreement_accepted_ip'] = null;
            $data['agreement_snapshot'] = null;
        }
        $lease->update($data);

        ActivityLogger::log('lease.updated', $lease);

        return new LeaseResource($lease->fresh(['room.images', 'room.landlord', 'tenant.role', 'landlord.role', 'charges']));
    }

    public function acceptAgreement(Request $request, Lease $lease)
    {
        abort_unless($request->user()->isTenant() && $lease->tenant_id === $request->user()->id, 404);
        $request->validate(['agreement_accepted' => ['required', 'accepted']]);

        return DB::transaction(function () use ($request, $lease) {
            $locked = Lease::with(['room', 'tenant', 'landlord'])->whereKey($lease->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active' || $locked->agreement_status === 'accepted') {
                throw ValidationException::withMessages(['agreement' => 'This lease agreement is no longer awaiting acceptance.']);
            }

            $locked->agreement_status = 'accepted';
            $locked->agreement_accepted_at = now();
            $locked->agreement_accepted_ip = $request->ip();
            $locked->agreement_snapshot = [
                'landlord_name' => $locked->landlord?->name ?? 'Landlord',
                'tenant_name' => $locked->tenant?->name ?? 'Tenant',
                'room_number' => $locked->room?->room_number ?? '—',
                'room_title' => $locked->room?->title ?? 'Rental room',
                'location' => $locked->room?->location ?? '',
                'monthly_rent' => (string) $locked->monthly_rent,
                'start_date' => $locked->start_date?->toDateString(),
                'end_date' => $locked->end_date?->toDateString(),
            ];
            $locked->save();
            ActivityLogger::log('lease.agreement_accepted', $locked);

            return response()->json(['lease' => new LeaseResource($locked->load(['room.images', 'tenant.role', 'landlord.role', 'charges', 'inspections']))]);
        });
    }

    public function agreementPdf(Request $request, Lease $lease, LeaseAgreementPdf $pdf)
    {
        $this->authorize('view', $lease);
        $lease->load(['room', 'tenant', 'landlord', 'charges', 'invoices', 'inspections']);
        $filename = 'BoardEase-Lease-Agreement-'.$lease->id.'.pdf';

        return response($pdf->generate($lease), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function inspection(Request $request, Lease $lease)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('update', $lease), 403);
        $data = $request->validate([
            'kind' => ['required', 'in:move_in,move_out'],
            'inspection_date' => ['required', 'date'],
            'condition' => ['required', 'string', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'damages_amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'damage_description' => ['nullable', 'string', 'max:1000'],
        ]);

        return DB::transaction(function () use ($request, $lease, $data) {
            $locked = Lease::with('room')->whereKey($lease->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active') {
                throw ValidationException::withMessages(['lease' => 'Inspections can only be recorded for an active lease.']);
            }
            $inspectionDate = $data['inspection_date'];
            if ($inspectionDate > BusinessTime::date()) {
                throw ValidationException::withMessages(['inspection_date' => 'A completed inspection cannot be dated in the future.']);
            }
            if ($inspectionDate < $locked->start_date->toDateString()) {
                throw ValidationException::withMessages(['inspection_date' => 'The inspection date cannot be before the lease start date.']);
            }
            if ($data['kind'] === 'move_in') {
                if ($locked->agreement_status !== 'accepted') {
                    throw ValidationException::withMessages(['agreement' => 'The tenant must accept the lease agreement before move-in.']);
                }
                if ($locked->moveInInspection()->exists()) {
                    throw ValidationException::withMessages(['kind' => 'A move-in inspection is already recorded.']);
                }
            } else {
                $moveIn = $locked->moveInInspection()->first();
                if (! $moveIn && $locked->agreement_status !== 'legacy') {
                    throw ValidationException::withMessages(['kind' => 'Record the move-in inspection before move-out.']);
                }
                if ($moveIn && $inspectionDate < $moveIn->inspection_date->toDateString()) {
                    throw ValidationException::withMessages(['inspection_date' => 'Move-out cannot be dated before the recorded move-in inspection.']);
                }
            }

            $inspection = LeaseInspection::create([
                'lease_id' => $locked->id,
                'recorded_by' => $request->user()->id,
                'kind' => $data['kind'],
                'inspection_date' => $data['inspection_date'],
                'condition' => $data['condition'],
                'notes' => $data['notes'] ?? null,
                'damages_amount' => $data['damages_amount'] ?? 0,
                'damage_description' => $data['damage_description'] ?? null,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            if ($data['kind'] === 'move_in') {
                $locked->room->update(['status' => 'occupied', 'active_room_id' => $locked->room_id]);
            } else {
                $locked->update(['status' => 'ended', 'end_date' => $data['inspection_date'], 'active_room_id' => null]);
                if ((float) ($data['damages_amount'] ?? 0) > 0) {
                    $damage = PendingCharge::create([
                        'lease_id' => $locked->id,
                        'category' => 'OTHER',
                        'description' => substr(trim('Move-out damage: '.($data['damage_description'] ?? 'Not specified')), 0, 255),
                        'amount' => $data['damages_amount'],
                        'status' => 'pending',
                    ]);
                    $invoice = $locked->invoices()->whereIn('status', [Invoice::UNPAID, Invoice::PARTIAL, Invoice::OVERDUE])->latest('billing_period')->first();
                    if ($invoice) {
                        $invoice->additional_charges_total = bcadd((string) $invoice->additional_charges_total, (string) $damage->amount, 2);
                        $invoice->total_amount_due = bcadd((string) $invoice->total_amount_due, (string) $damage->amount, 2);
                        $invoice->save();
                    } else {
                        $period = BusinessTime::period();
                        while ($locked->invoices()->where('billing_period', $period)->exists()) {
                            $period = \Carbon\Carbon::createFromFormat('Y-m', $period)->addMonth()->format('Y-m');
                        }
                        $invoice = Invoice::create([
                            'lease_id' => $locked->id,
                            'billing_period' => $period,
                            'base_rent' => 0,
                            'additional_charges_total' => $damage->amount,
                            'total_amount_due' => $damage->amount,
                            'amount_paid' => 0,
                            'status' => Invoice::UNPAID,
                            'due_date' => BusinessTime::today()->addDays(5)->toDateString(),
                        ]);
                    }
                    $damage->update(['status' => 'billed', 'invoice_id' => $invoice->id]);
                }
                $hasOffer = $locked->room->inquiries()->where('status', 'approved')->where('offer_expires_at', '>', now())->exists();
                $locked->room->update(['status' => $hasOffer ? 'reserved' : 'available']);
            }

            ActivityLogger::log('lease.'.$data['kind'].'_inspection', $inspection, ['lease_id' => $locked->id]);
            $locked->refresh()->load(['room.images', 'room.landlord', 'tenant.role', 'landlord.role', 'charges', 'inspections']);

            return response()->json(['inspection' => $inspection, 'lease' => new LeaseResource($locked)]);
        });
    }

    public function end(Request $request, Lease $lease)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('end', $lease), 403);

        if (! $lease->isActive()) {
            throw ValidationException::withMessages([
                'lease' => 'This lease is already ended.',
            ]);
        }

        throw ValidationException::withMessages([
            'lease' => 'Complete a move-out inspection to end this lease and release the room.',
        ]);
    }

    public function storeCharge(StoreChargeRequest $request, Lease $lease)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('charge', $lease), 403);
        $data = $request->validated();
        $data['billing_period'] = $data['billing_period'] ?? BusinessTime::period();

        [$charge, $idempotent] = DB::transaction(function () use ($lease, $data) {
            $lockedLease = Lease::whereKey($lease->id)->lockForUpdate()->firstOrFail();
            if (! $lockedLease->isActive()) {
                throw ValidationException::withMessages([
                    'lease' => 'Charges can only be added to an active lease.',
                ]);
            }

            if (! empty($data['client_uuid'])) {
                $existing = PendingCharge::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
                if ($existing) {
                    $matches = $existing->lease_id === $lockedLease->id
                        && $existing->category === $data['category']
                        && bccomp((string) $existing->amount, (string) $data['amount'], 2) === 0
                        && $existing->billing_period === $data['billing_period']
                        && $existing->utility_type === ($data['utility_type'] ?? null);
                    if (! $matches) {
                        throw ValidationException::withMessages([
                            'client_uuid' => ['This charge request reference has already been used for different charge details.'],
                        ]);
                    }

                    return [$existing, true];
                }
            }

            $paidInvoiceExists = Invoice::where('lease_id', $lockedLease->id)
                ->where('billing_period', $data['billing_period'])
                ->where('status', Invoice::PAID)
                ->exists();
            if ($paidInvoiceExists) {
                throw ValidationException::withMessages([
                    'billing_period' => ['This billing period is already paid. Choose a different billing period for the new charge.'],
                ]);
            }

            $charge = PendingCharge::create([
                'lease_id' => $lockedLease->id,
                'category' => $data['category'],
                'description' => $data['description'] ?? null,
                'amount' => $data['amount'],
                'status' => 'pending',
                'client_uuid' => $data['client_uuid'] ?? null,
                'billing_period' => $data['billing_period'],
                'utility_type' => $data['utility_type'] ?? null,
            ]);

            return [$charge, false];
        });

        if (! $idempotent) {
            ActivityLogger::log('charge.created', $charge, ['lease_id' => $lease->id, 'billing_period' => $charge->billing_period]);
            $charge->loadMissing('lease.tenant');
            if ($charge->lease?->tenant_id) {
                \App\Models\Notification::create([
                    'user_id' => $charge->lease->tenant_id,
                    'type' => 'charge.created',
                    'title' => 'Additional charge added',
                    'body' => 'A charge of PHP '.number_format((float) $charge->amount, 2).' was added to your '.$charge->billing_period.' billing period.'.($charge->description ? ' '.$charge->description : ''),
                    'data' => ['charge_id' => $charge->id, 'lease_id' => $charge->lease_id, 'invoice_id' => $charge->invoice_id, 'period' => $charge->billing_period],
                ]);
            }
        }

        return response()->json(['charge' => $charge, 'idempotent' => $idempotent], $idempotent ? 200 : 201);
    }

    public function voidCharge(Request $request, PendingCharge $charge)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('charge', $charge->lease), 403);

        if ($charge->status !== 'pending') {
            throw ValidationException::withMessages([
                'charge' => 'Only pending charges can be voided.',
            ]);
        }

        $charge->update(['status' => 'voided']);
        ActivityLogger::log('charge.voided', $charge);
        $charge->loadMissing('lease.tenant');
        if ($charge->lease?->tenant_id) {
            \App\Models\Notification::create([
                'user_id' => $charge->lease->tenant_id,
                'type' => 'charge.voided',
                'title' => 'Additional charge removed',
                'body' => 'A pending charge of PHP '.number_format((float) $charge->amount, 2).' for '.$charge->billing_period.' was voided.'.($charge->description ? ' '.$charge->description : ''),
                'data' => ['charge_id' => $charge->id, 'lease_id' => $charge->lease_id, 'period' => $charge->billing_period],
            ]);
        }

        return response()->json(['charge' => $charge->fresh()]);
    }
}
