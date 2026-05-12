<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuthSecurityEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserSecurityEvent extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'auth_session_id',
        'type',
        'risk_score',
        'risk_flags',
        'ip_address',
        'user_agent',
        'metadata',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => AuthSecurityEventType::class,
            'risk_score' => 'integer',
            'risk_flags' => 'array',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function authSession(): BelongsTo
    {
        return $this->belongsTo(UserAuthSession::class, 'auth_session_id');
    }
}
