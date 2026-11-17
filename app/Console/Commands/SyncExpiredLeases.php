<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Support\BusinessTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncExpiredLeases extends Command
{
    protected $signature = 'leases:sync-expired';

    protected $description = 'Report leases past their contract end date that still need a move-out inspection.';

    public function handle(): int
    {
        $today = BusinessTime::date();

        $expiredLeases = Lease::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<', $today)
            ->get();

        if ($expiredLeases->isEmpty()) {
            $this->info('No leases are past their contract end date.');
            return self::SUCCESS;
        }
        $this->warn($expiredLeases->count().' lease(s) have passed their contract end date. Their rooms remain occupied until a move-out inspection is completed.');

        return self::SUCCESS;
    }
}

