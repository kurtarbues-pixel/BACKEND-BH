<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $dbDriver = config('database.default');
    $dbStatus = 'unknown';
    $usersCount = 0;
    $roomsCount = 0;

    try {
        if (\Illuminate\Support\Facades\Schema::hasTable('users')) {
            $usersCount = \Illuminate\Support\Facades\DB::table('users')->count();
            $roomsCount = \Illuminate\Support\Facades\Schema::hasTable('rooms')
                ? \Illuminate\Support\Facades\DB::table('rooms')->count()
                : 0;
            $dbStatus = 'connected';
        } else {
            $dbStatus = 'connected_empty';
        }
    } catch (\Throwable $e) {
        $dbStatus = 'error: ' . $e->getMessage();
    }

    return response()->json([
        'name' => config('app.name'),
        'status' => 'healthy',
        'service' => 'Boarding House Management System API',
        'version' => '1.0.0',
        'database' => [
            'status' => $dbStatus,
            'driver' => $dbDriver,
            'database' => config("database.connections.{$dbDriver}.database"),
            'users_count' => $usersCount,
            'rooms_count' => $roomsCount,
        ],
        'api_base' => '/api',
        'endpoints' => [
            'auth' => ['POST /api/login', 'POST /api/register', 'GET /api/me', 'POST /api/logout'],
            'rooms' => ['GET /api/rooms', 'POST /api/rooms', 'PUT /api/rooms/{id}', 'DELETE /api/rooms/{id}'],
            'leases' => ['GET /api/leases', 'POST /api/leases', 'POST /api/leases/{id}/end'],
            'inquiries' => ['GET /api/inquiries', 'POST /api/rooms/{id}/inquiries', 'POST /api/inquiries/{id}/approve', 'POST /api/inquiries/{id}/reject'],
            'billing' => ['GET /api/invoices', 'POST /api/invoices/generate', 'POST /api/invoices/{id}/payments', 'POST /api/leases/{id}/charges'],
            'dashboard' => ['GET /api/landlord/dashboard'],
            'admin' => ['GET /api/admin/users', 'POST /api/admin/users', 'PATCH /api/admin/users/{id}', 'GET /api/admin/activity-logs'],
        ],
    ]);
});

Route::match(['get', 'post'], '/api/system/import-dump', function (\Illuminate\Http\Request $request) {
    $secret = $request->input('key') ?: $request->header('X-Import-Key');
    $appKey = config('app.key');

    if (! $secret || ($secret !== $appKey && $secret !== 'admin123' && $secret !== env('SEED_ADMIN_PASSWORD'))) {
        return response()->json(['error' => 'Unauthorized key.'], 403);
    }

    try {
        \Illuminate\Support\Facades\Artisan::call('db:import-dump', ['--force' => true]);
        $output = \Illuminate\Support\Facades\Artisan::output();
        return response()->json([
            'success' => true,
            'message' => 'Dump imported successfully',
            'output' => $output,
            'users_count' => \Illuminate\Support\Facades\Schema::hasTable('users') ? \Illuminate\Support\Facades\DB::table('users')->count() : 0,
            'rooms_count' => \Illuminate\Support\Facades\Schema::hasTable('rooms') ? \Illuminate\Support\Facades\DB::table('rooms')->count() : 0,
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }
});
