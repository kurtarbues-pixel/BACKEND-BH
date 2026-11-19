<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class ImportDumpCommand extends Command
{
    protected $signature = 'db:import-dump {file?}';
    protected $description = 'Import SQL dump into the current database';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?: database_path('bhms_dump.sql');

        if (! File::exists($filePath)) {
            $this->error("Dump file not found at: {$filePath}");
            return 1;
        }

        $this->info("Importing dump from {$filePath}...");

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            $sql = File::get($filePath);
            DB::unprepared($sql);

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $usersCount = DB::table('users')->count();
            $this->info("Successfully imported dump. Total users now: {$usersCount}");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Import failed: " . $e->getMessage());
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Throwable $ignored) {}
            return 1;
        }
    }
}
