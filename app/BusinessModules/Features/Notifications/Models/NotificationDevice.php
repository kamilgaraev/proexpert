<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Notifications\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationDevice extends Model
{
    protected $fillable = [
        'user_id',
        'installation_id',
        'platform',
        'provider',
        'token',
        'token_hash',
        'last_registered_at',
    ];

    protected $hidden = ['token', 'token_hash'];

    protected $casts = [
        'token' => 'encrypted',
        'last_registered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
