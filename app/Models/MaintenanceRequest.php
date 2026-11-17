<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRequest extends Model
{
    protected $fillable = ['lease_id', 'tenant_id', 'landlord_id', 'title', 'description', 'photo_path', 'status', 'resolved_at'];
    protected function casts(): array { return ['resolved_at' => 'datetime']; }
    public function lease(): BelongsTo { return $this->belongsTo(Lease::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(User::class, 'tenant_id'); }
    public function landlord(): BelongsTo { return $this->belongsTo(User::class, 'landlord_id'); }
}
