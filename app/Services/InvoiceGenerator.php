<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\PendingCharge;
use App\Models\Notification;
use App\Support\BusinessTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceGenerator
{
    public function generate(?string $period = null, ?int $landlordId = null): array
    {
        $period = $period ?: BusinessTime::period();
        $periodStart = Carbon::createFromFormat('!Y-m', $period, BusinessTime::timezone())->startOfMonth();
        $periodEnd = $periodStart->copy()->endOfMonth();
        $dueDate = $periodStart->copy()->addMonth()->addDays(4)->toDateString();

        $created = 0;
        $updated = 0;

        $leases = Lease::query()
            ->where('status', 'active')
            ->when($landlordId, fn ($query) => $query->where('landlord_id', $landlordId))
            ->whereDate('start_date', '<=', $periodEnd)
            ->where(function ($q) use ($periodStart) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $periodStart);
            })
            ->get();

        foreach ($leases as $lease) {
            DB::transaction(function () use ($lease, $period, $dueDate, &$created, &$updated) {
                $locked = Lease::whereKey($lease->id)->lockForUpdate()->first();

                $invoice = Invoice::where('lease_id', $locked->id)
                    ->where('billing_period', $period)
                    ->lockForUpdate()
                    ->first();

                $pending = PendingCharge::where('lease_id', $locked->id)
                    ->where('status', 'pending')
                    ->where(function ($query) use ($period) {
                        $query->whereNull('billing_period')->orWhere('billing_period', $period);
                    })
                    ->lockForUpdate()
                    ->get();

                $pendingTotal = $pending->reduce(
                    fn (string $total, PendingCharge $charge) => bcadd($total, (string) $charge->amount, 2),
                    '0.00',
                );

                if ($invoice && $invoice->status === Invoice::PAID) {
                    if ($pending->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'charges' => ['Pending charges cannot be added to an already paid invoice. Assign them to a different billing period.'],
                        ]);
                    }

                    return;
                }

                if (! $invoice) {
                    $total = bcadd((string) $locked->monthly_rent, $pendingTotal, 2);
                    $invoice = Invoice::create([
                        'lease_id' => $locked->id,
                        'billing_period' => $period,
                        'base_rent' => $locked->monthly_rent,
                        'additional_charges_total' => $pendingTotal,
                        'total_amount_due' => $total,
                        'amount_paid' => 0,
                        'status' => Invoice::UNPAID,
                        'due_date' => $dueDate,
                    ]);
                    $created++;

                    // Notify only the people involved in this rental invoice.
                    Notification::create([
                        'user_id' => $locked->tenant_id,
                        'type' => 'invoice.new',
                        'title' => 'New Billing Statement ('.$period.')',
                        'body' => 'Your billing statement for '.$period.' of ₱'.number_format((float) $total, 2).' is ready. Due date: '.$dueDate.'.',
                        'data' => [
                            'invoice_id' => $invoice->id,
                            'lease_id' => $locked->id,
                            'period' => $period,
                            'total' => $total,
                            'due_date' => $dueDate,
                        ],
                    ]);
                } else {
                    $additional = bcadd((string) $invoice->additional_charges_total, $pendingTotal, 2);
                    $invoice->update([
                        'additional_charges_total' => $additional,
                        'total_amount_due' => bcadd((string) $invoice->base_rent, $additional, 2),
                    ]);
                    $updated++;
                }

                if ($pending->isNotEmpty()) {
                    PendingCharge::whereIn('id', $pending->pluck('id'))
                        ->update([
                            'status' => 'billed',
                            'invoice_id' => $invoice->id,
                        ]);
                }

                if ($locked->landlord_id && ($invoice->wasRecentlyCreated || $pending->isNotEmpty())) {
                    Notification::create([
                        'user_id' => $locked->landlord_id,
                        'type' => $invoice->wasRecentlyCreated ? 'invoice.new' : 'invoice.updated',
                        'title' => $invoice->wasRecentlyCreated ? 'Invoice generated for your rental' : 'Invoice updated with additional charges',
                        'body' => 'The '.$period.' invoice for Room '.($locked->room?->room_number ?? '').' is '.($invoice->wasRecentlyCreated ? 'ready' : 'updated').'. Total: PHP '.number_format((float) $invoice->total_amount_due, 2).'.',
                        'data' => ['invoice_id' => $invoice->id, 'lease_id' => $locked->id, 'period' => $period],
                    ]);
                }

                if (! $invoice->wasRecentlyCreated && $pending->isNotEmpty()) {
                    Notification::create([
                        'user_id' => $locked->tenant_id,
                        'type' => 'invoice.updated',
                        'title' => 'Billing statement updated ('.$period.')',
                        'body' => 'Additional charges were added to your '.$period.' billing statement. Updated total: PHP '.number_format((float) $invoice->total_amount_due, 2).'.',
                        'data' => ['invoice_id' => $invoice->id, 'lease_id' => $locked->id, 'period' => $period],
                    ]);
                }
            });
        }

        return [
            'period' => $period,
            'created' => $created,
            'updated' => $updated,
        ];
    }

    public function markOverdue(): int
    {
        return Invoice::query()
            ->whereIn('status', [Invoice::UNPAID])
            ->whereDate('due_date', '<', BusinessTime::date())
            ->update(['status' => Invoice::OVERDUE]);
    }
}
