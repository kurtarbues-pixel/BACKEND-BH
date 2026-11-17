<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalReview extends Model
{
    protected $fillable = ['lease_id', 'room_id', 'tenant_id', 'rating', 'review', 'status', 'moderated_by', 'moderated_at'];
    protected function casts(): array { return ['rating' => 'integer', 'moderated_at' => 'datetime']; }
    public function lease(): BelongsTo { return $this->belongsTo(Lease::class); }
    public function room(): BelongsTo { return $this->belongsTo(Room::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(User::class, 'tenant_id'); }
}
