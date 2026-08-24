<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuthRegistrationAttempt extends Model
{
    protected $fillable = [
        'audience',
        'idempotency_key',
        'request_hash',
        'status',
        'user_id',
        'response',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'response' => 'array',
            'expires_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
