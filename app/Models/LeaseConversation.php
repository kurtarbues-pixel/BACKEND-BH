<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaseConversation extends Model
{
    protected $fillable = ['lease_id', 'tenant_id', 'landlord_id'];
    public function lease(): BelongsTo { return $this->belongsTo(Lease::class); }
    public function tenant(): BelongsTo { return $this->belongsTo(User::class, 'tenant_id'); }
    public function landlord(): BelongsTo { return $this->belongsTo(User::class, 'landlord_id'); }
    public function messages(): HasMany { return $this->hasMany(ConversationMessage::class, 'conversation_id'); }
}
