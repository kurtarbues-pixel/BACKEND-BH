<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_number' => 'INV-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'billing_period' => $this->billing_period,
            'base_rent' => $this->base_rent,
            'additional_charges_total' => $this->additional_charges_total,
            'total_amount_due' => $this->total_amount_due,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->remainingBalance(),
            'status' => ucfirst($this->status),
            'due_date' => $this->due_date?->toDateString(),
            'lease' => $this->whenLoaded('lease', fn () => new LeaseResource($this->lease)),
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'category' => $charge->category,
                'description' => $charge->description,
                'utility_type' => $charge->utility_type,
                'billing_period' => $charge->billing_period ?: $this->billing_period,
                'amount' => $charge->amount,
                'status' => ucfirst($charge->status),
            ])),
            'payments' => $this->whenLoaded('payments', fn () => $this->payments->map(fn ($payment) => [
                'id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'reference_number' => $payment->reference_number,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'transaction_status' => $payment->transaction_status ?? 'succeeded',
                'failure_reason' => $payment->failure_reason,
                'notes' => $payment->notes,
                'paid_at' => $payment->paid_at,
                'recorded_by' => $payment->recorded_by,
                'created_at' => $payment->created_at,
            ])),
        ];
    }
}
