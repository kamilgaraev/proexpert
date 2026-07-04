<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SafetyInspection extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id',
        'project_id',
        'template_id',
        'permit_id',
        'conducted_by_user_id',
        'inspection_number',
        'title',
        'inspection_type',
        'location_name',
        'risk_level',
        'status',
        'planned_at',
        'conducted_at',
        'result',
        'summary',
        'metadata',
    ];

    protected $casts = [
        'planned_at' => 'datetime',
        'conducted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(SafetyInspectionTemplate::class, 'template_id');
    }

    public function permit(): BelongsTo
    {
        return $this->belongsTo(SafetyWorkPermit::class, 'permit_id');
    }

    public function conductedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conducted_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SafetyInspectionItem::class, 'inspection_id');
    }

    public function findings(): HasMany
    {
        return $this->hasMany(SafetyInspectionFinding::class, 'inspection_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
