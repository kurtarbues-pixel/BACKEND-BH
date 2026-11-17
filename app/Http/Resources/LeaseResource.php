<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'monthly_rent' => $this->monthly_rent,
            'outstanding_balance' => number_format((float) ($this->invoices()->selectRaw('COALESCE(SUM(total_amount_due - amount_paid), 0) as balance')->value('balance') ?? 0), 2, '.', ''),
            'status' => $this->status,
            'agreement_status' => $this->agreement_status,
            'agreement_accepted_at' => $this->agreement_accepted_at,
            'agreement_snapshot' => $this->agreementSnapshot(),
            'inspections' => $this->whenLoaded('inspections', fn () => $this->inspections->map(fn ($inspection) => [
                'id' => $inspection->id,
                'kind' => $inspection->kind,
                'inspection_date' => $inspection->inspection_date?->toDateString(),
                'condition' => $inspection->condition,
                'notes' => $inspection->notes,
                'damages_amount' => $inspection->damages_amount,
                'damage_description' => $inspection->damage_description,
                'status' => $inspection->status,
                'completed_at' => $inspection->completed_at,
            ])),
            'room' => $this->whenLoaded('room', fn () => new RoomResource($this->room)),
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant->toAuthArray()),
            'landlord' => $this->whenLoaded('landlord', fn () => $this->landlord->toAuthArray()),
            'charges' => $this->whenLoaded('charges', fn () => $this->charges->map(fn ($charge) => [
                'id' => $charge->id,
                'category' => $charge->category,
                'description' => $charge->description,
                'utility_type' => $charge->utility_type,
                'billing_period' => $charge->billing_period,
                'amount' => $charge->amount,
                'status' => $charge->status,
                'invoice_id' => $charge->invoice_id,
                'created_at' => $charge->created_at,
            ])),
        ];
    }
}
