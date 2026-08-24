<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserConsent extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'version',
        'accepted_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
