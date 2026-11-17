<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaseInspection extends Model
{
    protected $fillable = [
        'lease_id', 'recorded_by', 'kind', 'inspection_date', 'condition', 'notes',
        'damages_amount', 'damage_description', 'status', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'inspection_date' => 'date',
            'damages_amount' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
