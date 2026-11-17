<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\Notification;
use App\Support\BusinessTime;
use Illuminate\Console\Command;

class NotifyUpcomingLeaseEnd extends Command
{
    protected $signature = 'leases:notify-upcoming-end';

    protected $description = 'Notify tenants and landlords 30, 7, and 1 day before a lease end date.';

    public function handle(): int
    {
        $reminders = 0;
        foreach ([30, 7, 1] as $days) {
            $targetDate = BusinessTime::today()->addDays($days)->toDateString();
            $leases = Lease::with(['room', 'tenant', 'landlord'])
                ->where('status', 'active')
                ->whereDate('end_date', $targetDate)
                ->get();

            foreach ($leases as $lease) {
                foreach ([$lease->tenant, $lease->landlord] as $user) {
                    if (! $user) continue;
                    $alreadySent = Notification::query()
                        ->where('user_id', $user->id)
                        ->where('type', 'lease.reminder')
                        ->where('data->lease_id', $lease->id)
                        ->where('data->days_remaining', $days)
                        ->where('data->end_date', $lease->end_date?->toDateString())
                        ->exists();
                    if ($alreadySent) continue;

                    Notification::create([
                        'user_id' => $user->id,
                        'type' => 'lease.reminder',
                        'title' => 'Lease ending soon',
                        'body' => 'The lease for Room '.($lease->room?->room_number ?? '').' ends in '.$days.' day'.($days === 1 ? '' : 's').' ('.$lease->end_date?->toDateString().').',
                        'data' => ['lease_id' => $lease->id, 'room_id' => $lease->room_id, 'days_remaining' => $days, 'end_date' => $lease->end_date?->toDateString()],
                    ]);
                    $reminders++;
                }
            }
        }

        $this->info($reminders.' lease reminder(s) created.');
        return self::SUCCESS;
    }
}
