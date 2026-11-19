<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class RoomImage extends Model
{
    protected $fillable = ['room_id', 'path', 'original_name', 'sort_order'];

    protected $appends = ['url'];

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function getUrlAttribute(): string
    {
        if (! $this->path || ! Storage::disk('public')->exists($this->path)) {
            $fallbacks = [
                'https://images.unsplash.com/photo-1522708323590-d24dbb6b0267?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1598928506311-c55ded91a20c?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1505691938895-1758d7feb511?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=800&q=80',
                'https://images.unsplash.com/photo-1540518614846-7ede433c4ef4?auto=format&fit=crop&w=800&q=80',
            ];
            $index = abs(crc32((string) ($this->room_id . '-' . $this->id))) % count($fallbacks);
            return $fallbacks[$index];
        }

        return Storage::disk('public')->url($this->path);
    }
}
