<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models\WorkforceEmployee;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyWorkPermitParticipant extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'permit_id',
        'employee_id',
        'user_id',
        'external_name',
        'company_name',
        'role_name',
        'position_name',
        'work_category',
        'admission_status',
        'admission_checked_at',
        'admission_blockers',
        'admission_warnings',
        'metadata',
    ];

    protected $casts = [
        'admission_checked_at' => 'datetime',
        'admission_blockers' => 'array',
        'admission_warnings' => 'array',
        'metadata' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function permit(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkPermit::class, 'permit_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(WorkforceEmployee::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
