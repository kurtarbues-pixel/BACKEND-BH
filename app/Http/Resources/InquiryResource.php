<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InquiryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'message' => $this->message,
            'desired_start_date' => $this->desired_start_date?->toDateString(),
            'desired_end_date' => $this->desired_end_date?->toDateString(),
            'status' => ucfirst($this->status),
            'offer_expires_at' => $this->offer_expires_at,
            'decision_reason' => $this->decision_reason,
            'room' => $this->whenLoaded('room', fn () => new RoomResource($this->room)),
            'tenant' => $this->whenLoaded('tenant', fn () => $this->tenant->toAuthArray()),
            'created_at' => $this->created_at,
        ];
    }
}
