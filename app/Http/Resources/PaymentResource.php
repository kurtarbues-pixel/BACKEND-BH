<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_id' => $this->invoice_id,
            'reference_number' => $this->reference_number,
            'amount' => $this->amount,
            'method' => $this->method,
            'transaction_status' => $this->transaction_status ?? 'succeeded',
            'failure_reason' => $this->failure_reason,
            'notes' => $this->notes,
            'recorded_by' => $this->recorded_by,
            'paid_at' => $this->paid_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
