<?php

namespace App\BusinessModules\Features\AIAssistant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'ai_messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tokens_used',
        'model',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }
}

