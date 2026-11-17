<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isAdmin() || $request->user()->isLandlord(), 403);

        $query = User::with('role')
            ->whereHas('role', fn ($query) => $query->where('name', Role::TENANT))
            ->where('is_active', true);

        if ($request->user()->isLandlord()) {
            $query->where(function ($scope) use ($request) {
                $scope->whereHas('tenantLeases', fn ($leases) => $leases->where('landlord_id', $request->user()->id))
                    ->orWhereHas('inquiries.room', fn ($rooms) => $rooms->where('landlord_id', $request->user()->id));
            });
        }

        $tenants = $query->orderBy('name')->paginate(50)
            ->through(fn (User $tenant) => $tenant->toAuthArray());

        return response()->json(['data' => $tenants->items(), 'meta' => [
            'current_page' => $tenants->currentPage(),
            'last_page' => $tenants->lastPage(),
            'per_page' => $tenants->perPage(),
            'total' => $tenants->total(),
        ]]);
    }
}
