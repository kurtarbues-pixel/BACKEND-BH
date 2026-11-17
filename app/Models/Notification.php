<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = ['user_id', 'type', 'title', 'body', 'data', 'read_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function notifyAdministrators(string $type, string $title, string $body, array $data = []): void
    {
        $platformTypes = [
            'system.report',
            'system.security',
            'system.moderation',
            'system.account_moderation',
            'system.announcement',
            'system.error',
        ];
        if (! collect($platformTypes)->contains(fn (string $platformType) => str_starts_with($type, $platformType))) {
            throw new \InvalidArgumentException('Administrator notifications are reserved for platform-level events.');
        }

        User::query()->whereHas('role', fn ($query) => $query->where('name', Role::ADMIN))
            ->pluck('id')
            ->each(fn ($userId) => static::create(['user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body, 'data' => $data]));
    }
}
