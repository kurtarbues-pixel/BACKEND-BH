<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Lease extends Model
{
    use HasFactory;

    protected $fillable = [
        'room_id',
        'tenant_id',
        'landlord_id',
        'inquiry_id',
        'start_date',
        'end_date',
        'monthly_rent',
        'status',
        'active_room_id',
        'agreement_status',
        'agreement_accepted_at',
        'agreement_accepted_ip',
        'agreement_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'monthly_rent' => 'decimal:2',
            'agreement_accepted_at' => 'datetime',
            'agreement_snapshot' => 'array',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tenant_id');
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(PendingCharge::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(LeaseInspection::class)->orderBy('inspection_date');
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(MaintenanceRequest::class);
    }

    public function conversation(): HasOne
    {
        return $this->hasOne(LeaseConversation::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(RentalReview::class);
    }

    public function moveInInspection(): HasOne
    {
        return $this->hasOne(LeaseInspection::class)->where('kind', 'move_in');
    }

    public function moveOutInspection(): HasOne
    {
        return $this->hasOne(LeaseInspection::class)->where('kind', 'move_out');
    }

    public function agreementSnapshot(): array
    {
        return $this->agreement_snapshot ?? [
            'landlord_name' => $this->landlord?->name ?? 'Landlord',
            'tenant_name' => $this->tenant?->name ?? 'Tenant',
            'room_number' => $this->room?->room_number ?? '—',
            'room_title' => $this->room?->title ?? 'Rental room',
            'location' => $this->room?->location ?? '',
            'monthly_rent' => (string) $this->monthly_rent,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
        ];
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
