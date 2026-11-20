<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ImportDumpCommand extends Command
{
    protected $signature = 'db:import-dump {file?} {--force : Force overwrite even if database is already populated}';
    protected $description = 'Import SQL dump into the current database';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?: database_path('bhms_dump.sql');

        if (! File::exists($filePath)) {
            $this->error("Dump file not found at: {$filePath}");
            return 1;
        }

        $driver = DB::getDriverName();
        $isForce = (bool) $this->option('force') || env('FORCE_IMPORT_DUMP', false);

        // Check if database is already populated
        if (! $isForce) {
            try {
                if (Schema::hasTable('users') && DB::table('users')->count() > 0) {
                    $this->info('Database is already populated with users. Skipping import (pass --force to overwrite).');
                    return 0;
                }
            } catch (\Throwable $e) {
                // Table doesn't exist or error reading, proceed with import
            }
        }

        $this->info("Importing dump from {$filePath} (driver: {$driver})...");

        try {
            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0;');
                $tables = DB::select('SHOW TABLES');
                foreach ($tables as $t) {
                    $props = array_values((array)$t);
                    if (! empty($props[0])) {
                        DB::statement("DROP TABLE IF EXISTS `{$props[0]}`");
                    }
                }
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = OFF;');
                $tables = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");
                foreach ($tables as $t) {
                    if (! empty($t->name)) {
                        DB::statement("DROP TABLE IF EXISTS `{$t->name}`");
                    }
                }
            }

            $sql = File::get($filePath);
            DB::unprepared($sql);

            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys = ON;');
            }

            $usersCount = Schema::hasTable('users') ? DB::table('users')->count() : 0;
            $roomsCount = Schema::hasTable('rooms') ? DB::table('rooms')->count() : 0;
            $this->info("Successfully imported dump. Total users: {$usersCount}, Total rooms: {$roomsCount}");
            return 0;
        } catch (\Throwable $e) {
            $this->error("Import failed: " . $e->getMessage());
            try {
                if ($driver === 'mysql') {
                    DB::statement('SET FOREIGN_KEY_CHECKS=1;');
                } elseif ($driver === 'sqlite') {
                    DB::statement('PRAGMA foreign_keys = ON;');
                }
            } catch (\Throwable $ignored) {}
            return 1;
        }
    }
}
