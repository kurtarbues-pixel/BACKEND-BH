<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePaymentRequest;
use App\Http\Requests\StoreSimulatedPaymentRequest;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\BillingDocumentPdf;
use App\Services\ActivityLogger;
use App\Services\InvoiceGenerator;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Str;

class InvoiceController extends Controller
{
    public function generate(Request $request, InvoiceGenerator $generator)
    {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m,Y-m-d'],
        ], [
            'period.date_format' => 'Choose a valid billing month or invoice date.',
        ]);

        if (isset($data['period'])) {
            $dateParts = explode('-', $data['period']);
            $year = (int) ($dateParts[0] ?? 0);
            $month = (int) ($dateParts[1] ?? 0);
            $day = isset($dateParts[2]) ? (int) $dateParts[2] : 1;

            if (! checkdate($month, $day, $year)) {
                throw ValidationException::withMessages([
                    'period' => ['Choose a real calendar date for the billing month.'],
                ]);
            }
        }

        $landlordId = $request->user()->isAdmin() ? null : $request->user()->id;

        $period = isset($data['period']) ? substr($data['period'], 0, 7) : null;
        return response()->json($generator->generate($period, $landlordId));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Invoice::query()->with(['lease.room', 'lease.tenant.role', 'charges', 'payments']);

        if ($user->isTenant()) {
            $query->whereHas('lease', fn ($q) => $q->where('tenant_id', $user->id));
        } elseif ($user->isLandlord()) {
            $query->whereHas('lease', fn ($q) => $q->where('landlord_id', $user->id));
        } elseif (! $user->isAdmin()) {
            abort(403);
        }

        return InvoiceResource::collection($query->latest()->paginate(20));
    }

    public function show(Request $request, Invoice $invoice)
    {
        $this->authorize('view', $invoice);
        $invoice->load(['lease.room', 'lease.tenant.role', 'charges', 'payments']);

        return new InvoiceResource($invoice);
    }

    public function downloadPdf(Request $request, Invoice $invoice, BillingDocumentPdf $pdf)
    {
        $this->authorize('view', $invoice);
        $invoice->load(['lease.room', 'lease.tenant', 'lease.landlord', 'charges', 'payments']);

        return response($pdf->invoice($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="BoardEase-Invoice-'.$invoice->id.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function downloadReceipt(Request $request, Payment $payment, BillingDocumentPdf $pdf)
    {
        $payment->load(['invoice.lease.room', 'invoice.lease.tenant', 'invoice.lease.landlord', 'invoice.charges']);
        abort_unless($payment->invoice, 404);
        $this->authorize('view', $payment->invoice);
        abort_unless($payment->transaction_status === 'succeeded' && $payment->paid_at !== null, 404, 'A receipt is available only for a successfully recorded payment.');

        return response($pdf->receipt($payment), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="BoardEase-Receipt-'.($payment->reference_number ?: $payment->id).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function current(Request $request)
    {
        $user = $request->user();
        $period = BusinessTime::period();

        $query = Invoice::query()
            ->with(['lease.room', 'charges', 'payments'])
            ->where('billing_period', $period);

        if ($user->isTenant()) {
            $query->whereHas('lease', fn ($q) => $q->where('tenant_id', $user->id)->where('status', 'active'));
        } else {
            abort(403);
        }

        $invoice = $query->first();

        return $invoice
            ? new InvoiceResource($invoice)
            : response()->json(['data' => null]);
    }

    public function pay(StorePaymentRequest $request, Invoice $invoice)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->can('pay', $invoice), 403);

        $amount = $request->validated('amount');
        DB::transaction(function () use ($request, $invoice, $amount) {
            $lockedInvoice = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $remaining = (float) $lockedInvoice->remainingBalance();
            if ($lockedInvoice->status === Invoice::PAID) {
                throw ValidationException::withMessages(['invoice' => 'This invoice is already paid.']);
            }
            if ($amount - $remaining > 0.009) {
                throw ValidationException::withMessages(['amount' => 'Payment exceeds the remaining balance of '.$remaining.'.']);
            }

            Payment::create([
                'invoice_id' => $lockedInvoice->id,
                'recorded_by' => $request->user()->id,
                'amount' => $amount,
                'method' => $request->validated('method') ?? 'cash',
                'notes' => $request->validated('notes'),
                'paid_at' => now(),
            ]);

            $lockedInvoice->amount_paid = bcadd((string) $lockedInvoice->amount_paid, (string) $amount, 2);
            $lockedInvoice->refreshPaymentStatus();
        });

        $invoice->refresh();
        ActivityLogger::log('payment.recorded', $invoice, ['amount' => $amount]);

        // Notify tenant of payment confirmation
        if ($invoice->lease?->tenant_id) {
            \App\Models\Notification::create([
                'user_id' => $invoice->lease->tenant_id,
                'type' => 'payment.received',
                'title' => 'Payment Acknowledged',
                'body' => 'Your payment of ₱'.number_format((float) $amount, 2).' for billing period '.$invoice->billing_period.' has been recorded.',
                'data' => [
                    'invoice_id' => $invoice->id,
                    'amount' => $amount,
                    'status' => $invoice->status,
                    'remaining' => $invoice->remainingBalance(),
                ],
            ]);
        }
        if ($invoice->lease?->landlord_id) {
            \App\Models\Notification::create([
                'user_id' => $invoice->lease->landlord_id,
                'type' => 'payment.received',
                'title' => 'Tenant payment recorded',
                'body' => $invoice->lease->tenant?->name.' paid PHP '.number_format((float) $amount, 2).' for invoice '.$invoice->billing_period.'.',
                'data' => ['invoice_id' => $invoice->id, 'amount' => $amount, 'tenant_id' => $invoice->lease->tenant_id],
            ]);
        }
        return new InvoiceResource($invoice->fresh(['lease.room', 'charges', 'payments']));
    }

    public function simulatePay(StoreSimulatedPaymentRequest $request, Invoice $invoice)
    {
        $data = $request->validated();
        $amount = number_format((float) $data['amount'], 2, '.', '');

        [$payment, $idempotent] = DB::transaction(function () use ($request, $invoice, $data, $amount) {
            $lockedInvoice = Invoice::with('lease')->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            abort_unless($lockedInvoice->lease?->tenant_id === $request->user()->id, 403);

            $existing = Payment::where('client_uuid', $data['client_uuid'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->invoice_id !== $lockedInvoice->id
                    || bccomp((string) $existing->amount, $amount, 2) !== 0
                    || $existing->method !== $data['method']) {
                    throw ValidationException::withMessages([
                        'client_uuid' => ['This payment request reference has already been used for a different payment.'],
                    ]);
                }

                return [$existing, true];
            }

            $remaining = $lockedInvoice->remainingBalance();
            if (bccomp($remaining, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['invoice' => ['This invoice has no outstanding balance.']]);
            }
            if (bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages(['amount' => ['Payment exceeds the remaining invoice balance of '.$remaining.'.']]);
            }

            $failed = (bool) ($data['simulate_failure'] ?? false);
            $payment = Payment::create([
                'invoice_id' => $lockedInvoice->id,
                'reference_number' => 'BE-SIM-'.Str::upper((string) Str::uuid()),
                'client_uuid' => $data['client_uuid'],
                'recorded_by' => $request->user()->id,
                'amount' => $amount,
                'transaction_status' => $failed ? 'failed' : 'succeeded',
                'method' => $data['method'],
                'notes' => 'Simulated online payment. No real money was processed.',
                'failure_reason' => $failed ? 'Simulated transaction declined for demonstration.' : null,
                'paid_at' => $failed ? null : now(),
            ]);

            if (! $failed) {
                $lockedInvoice->amount_paid = bcadd((string) $lockedInvoice->amount_paid, $amount, 2);
                $lockedInvoice->refreshPaymentStatus();

                \App\Models\Notification::create([
                    'user_id' => $request->user()->id,
                    'type' => 'payment.received',
                    'title' => 'Simulated payment successful',
                    'body' => 'Your simulated payment of ₱'.number_format((float) $amount, 2).' for '.$lockedInvoice->billing_period.' was recorded. No real money was processed.',
                    'data' => ['invoice_id' => $lockedInvoice->id, 'payment_reference' => $payment->reference_number],
                ]);
                if ($lockedInvoice->lease?->landlord_id) {
                    \App\Models\Notification::create([
                        'user_id' => $lockedInvoice->lease->landlord_id,
                        'type' => 'payment.received',
                        'title' => 'Tenant payment received',
                        'body' => $request->user()->name.' made a simulated payment of PHP '.number_format((float) $amount, 2).' for '.$lockedInvoice->billing_period.'.',
                        'data' => ['invoice_id' => $lockedInvoice->id, 'payment_reference' => $payment->reference_number, 'tenant_id' => $request->user()->id],
                    ]);
                }
            } else {
                \App\Models\Notification::create([
                    'user_id' => $request->user()->id,
                    'type' => 'payment.failed',
                    'title' => 'Simulated payment failed',
                    'body' => 'Your simulated payment attempt for '.$lockedInvoice->billing_period.' was declined. Your invoice balance was not changed.',
                    'data' => ['invoice_id' => $lockedInvoice->id, 'payment_reference' => $payment->reference_number],
                ]);
            }

            return [$payment, false];
        });

        ActivityLogger::log(
            $payment->transaction_status === 'succeeded' ? 'payment.simulated_succeeded' : 'payment.simulated_failed',
            $payment,
            ['invoice_id' => $invoice->id, 'reference_number' => $payment->reference_number, 'idempotent' => $idempotent],
        );

        $payment->load('invoice');

        return response()->json([
            'payment' => new PaymentResource($payment),
            'invoice' => new InvoiceResource($invoice->fresh(['lease.room', 'charges', 'payments'])),
            'idempotent' => $idempotent,
        ], $payment->transaction_status === 'failed' ? 200 : 201);
    }
}
