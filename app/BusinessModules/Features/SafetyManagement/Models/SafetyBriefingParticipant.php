<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SafetyBriefingParticipant extends Model
{
    protected $fillable = [
        'briefing_id',
        'user_id',
        'external_name',
        'company_name',
        'role_name',
        'signed_at',
        'metadata',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function briefing(): BelongsTo
    {
        return $this->belongsTo(SafetyBriefing::class, 'briefing_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
