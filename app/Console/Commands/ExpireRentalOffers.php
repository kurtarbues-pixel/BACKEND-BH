<?php

namespace App\Console\Commands;

use App\Models\Inquiry;
use App\Models\Lease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireRentalOffers extends Command
{
    protected $signature = 'inquiries:expire-offers';

    protected $description = 'Release rooms when a tenant rental offer expires.';

    public function handle(): int
    {
        $expired = Inquiry::query()
            ->where('status', Inquiry::APPROVED)
            ->whereNotNull('offer_expires_at')
            ->where('offer_expires_at', '<=', now())
            ->pluck('id');

        foreach ($expired as $id) {
            DB::transaction(function () use ($id) {
                $inquiry = Inquiry::whereKey($id)->lockForUpdate()->first();
                if (! $inquiry || $inquiry->status !== Inquiry::APPROVED || $inquiry->offer_expires_at?->isFuture()) {
                    return;
                }

                $room = \App\Models\Room::whereKey($inquiry->room_id)->lockForUpdate()->first();
                $inquiry->update(['status' => Inquiry::REJECTED, 'decision_reason' => 'The rental offer expired without a response.']);
                if ($room && ! Lease::where('active_room_id', $room->id)->exists()) {
                    $hasOtherOffer = Inquiry::where('room_id', $room->id)
                        ->where('status', Inquiry::APPROVED)
                        ->where('offer_expires_at', '>', now())
                        ->exists();
                    $room->update(['status' => $hasOtherOffer ? 'reserved' : 'available']);
                }

                \App\Services\ActivityLogger::log('inquiry.offer_expired', $inquiry);
            });
        }

        $this->info($expired->count().' rental offer(s) expired.');
        return self::SUCCESS;
    }
}
