<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'room_number' => $this->room_number,
            'title' => $this->title,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'description' => $this->description,
            'location' => $this->location,
            'price' => $this->price,
            'amenities' => $this->amenities ?? [],
            'status' => $this->status,
            'is_reserved' => $this->status === 'reserved',
            'average_rating' => number_format((float) $this->reviews()->where('status', 'approved')->avg('rating'), 1, '.', ''),
            'review_count' => $this->reviews()->where('status', 'approved')->count(),
            'landlord_id' => $request->user()?->isAdmin() || $request->user()?->isLandlord() ? $this->landlord_id : null,
            'landlord' => $this->whenLoaded('landlord', fn () => [
                'id' => $this->landlord->id,
                'name' => $this->landlord->name,
                'email' => $request->user() ? $this->landlord->email : null,
                'phone' => $request->user() ? $this->landlord->phone : null,
            ]),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => $image->url,
            ])),
            'created_at' => $this->created_at,
        ];
    }
}
