<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Models;

use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPulseReport extends Model
{
    protected $fillable = [
        'organization_id',
        'project_id',
        'scope_type',
        'report_date',
        'period_preset',
        'period_from',
        'period_to',
        'status',
        'ai_status',
        'ai_provider',
        'summary',
        'metrics',
        'urgent_actions',
        'risk_groups',
        'finance',
        'activity',
        'recommendations',
        'raw_facts',
        'created_by_user_id',
        'generated_at',
    ];

    protected $casts = [
        'report_date' => 'date',
        'period_from' => 'datetime',
        'period_to' => 'datetime',
        'summary' => 'array',
        'metrics' => 'array',
        'urgent_actions' => 'array',
        'risk_groups' => 'array',
        'finance' => 'array',
        'activity' => 'array',
        'recommendations' => 'array',
        'raw_facts' => 'array',
        'generated_at' => 'datetime',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForProject(Builder $query, ?int $projectId): Builder
    {
        return $projectId === null
            ? $query->whereNull('project_id')
            : $query->where('project_id', $projectId);
    }
}
