<?php

namespace App\Services;

use App\Models\Inquiry;
use App\Models\Lease;
use App\Models\Room;
use App\Models\RoomAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class RoomAvailabilityService
{
    public function assertAvailable(Room $room, string $startDate, ?string $endDate = null, ?int $exceptInquiryId = null, ?int $exceptLeaseId = null): void
    {
        $endDate ??= '9999-12-31';

        if ($room->status === 'maintenance') {
            throw ValidationException::withMessages(['dates' => 'This room is under maintenance.']);
        }

        $lease = Lease::query()->where('room_id', $room->id)->where('status', 'active')
            ->when($exceptLeaseId, fn ($query) => $query->where('id', '!=', $exceptLeaseId))
            ->first();
        if ($lease) {
            throw ValidationException::withMessages(['dates' => 'This room remains unavailable until its move-out inspection is completed.']);
        }

        if ($room->status === 'occupied' && ! $exceptLeaseId) {
            throw ValidationException::withMessages(['dates' => 'This room remains occupied until move-out is completed.']);
        }

        $blocked = RoomAvailabilityBlock::query()->where('room_id', $room->id)
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->exists();
        if ($blocked) {
            throw ValidationException::withMessages(['dates' => 'The requested dates overlap with landlord-blocked availability.']);
        }

        $offer = Inquiry::query()->where('room_id', $room->id)
            ->where('status', Inquiry::APPROVED)
            ->where('offer_expires_at', '>', now())
            ->when($exceptInquiryId, fn ($query) => $query->where('id', '!=', $exceptInquiryId))
            ->where(function ($query) use ($startDate, $endDate) {
                $query->whereNull('desired_start_date')
                    ->orWhere(function ($dated) use ($startDate, $endDate) {
                        $dated->whereDate('desired_start_date', '<=', $endDate)
                            ->where(function ($range) use ($startDate) {
                                $range->whereNull('desired_end_date')->orWhereDate('desired_end_date', '>=', $startDate);
                            });
                    });
            })
            ->exists();
        if ($offer) {
            throw ValidationException::withMessages(['dates' => 'These dates are currently held by another rental offer.']);
        }

        if (! in_array($room->status, ['available', 'reserved'], true) && ! ($room->status === 'occupied' && $exceptLeaseId)) {
            throw ValidationException::withMessages(['dates' => 'This room is not currently available.']);
        }
    }

    public function calendar(Room $room, string $startDate, string $endDate): array
    {
        $start = CarbonImmutable::parse($startDate);
        $end = CarbonImmutable::parse($endDate);
        $blocks = $room->availabilityBlocks()
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate)
            ->get();
        $leases = Lease::query()->where('room_id', $room->id)->where('status', 'active')
            ->whereDate('start_date', '<=', $endDate)
            ->where(fn ($query) => $query->whereNull('end_date')->orWhereDate('end_date', '>=', $startDate))
            ->exists();
        $offer = Inquiry::query()->where('room_id', $room->id)
            ->where('status', Inquiry::APPROVED)->where('offer_expires_at', '>', now())
            ->first();
        $days = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $date = $day->toDateString();
            $reason = null;

            if ($room->status === 'maintenance') {
                $reason = 'Under maintenance';
            } elseif ($leases || $room->status === 'occupied') {
                $reason = 'Move-out inspection required';
            } elseif ($offer && (! $offer->desired_start_date || ($date >= $offer->desired_start_date->toDateString() && (! $offer->desired_end_date || $date <= $offer->desired_end_date->toDateString())))) {
                $reason = 'Held for a rental offer';
            } else {
                $block = $blocks->first(fn ($item) => $date >= $item->start_date->toDateString() && $date <= $item->end_date->toDateString());
                if ($block) {
                    $reason = $block->reason ?: 'Unavailable';
                } elseif ($room->status !== 'available' && $room->status !== 'reserved') {
                    $reason = 'Unavailable';
                }
            }

            $days[] = ['date' => $date, 'available' => $reason === null, 'reason' => $reason];
        }

        return $days;
    }
}
