<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SafetyBriefingParticipant extends Model
{
    protected $fillable = [
        'briefing_id',
        'employee_id',
        'user_id',
        'external_name',
        'company_name',
        'role_name',
        'signature_status',
        'signed_at',
        'signed_by_user_id',
        'signature_method',
        'refusal_reason',
        'absence_reason',
        'signature_metadata',
        'metadata',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
        'signature_metadata' => 'array',
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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(WorkforceEmployee::class, 'employee_id');
    }

    public function signedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by_user_id');
    }
}
