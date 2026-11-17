<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/** Serializes rental creation paths on the tenant row and enforces one active lease. */
class TenantActiveRental
{
    public function lockTenant(int $tenantId): User
    {
        return User::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
    }

    public function assertNoActiveLease(int $tenantId): void
    {
        if (Lease::query()->where('tenant_id', $tenantId)->where('status', 'active')->exists()) {
            throw ValidationException::withMessages([
                'rental' => 'You already have an active rental. You can apply for another room after your current lease has officially ended.',
            ]);
        }
    }
}
