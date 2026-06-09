<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WipForecastVersion extends BudgetingModel
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'project_id',
        'budget_version_id',
        'scenario_id',
        'previous_version_id',
        'version_number',
        'name',
        'description',
        'status',
        'period_start',
        'period_end',
        'as_of_date',
        'currency',
        'group_by',
        'source_snapshot_hash',
        'source_snapshot',
        'summary',
        'formulas',
        'source_coverage',
        'freshness',
        'actions',
        'meta',
        'workflow_history',
        'created_by',
        'submitted_by',
        'approved_by',
        'activated_by',
        'submitted_at',
        'approved_at',
        'activated_at',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'as_of_date' => 'date',
        'group_by' => 'array',
        'source_snapshot' => 'array',
        'summary' => 'array',
        'formulas' => 'array',
        'source_coverage' => 'array',
        'freshness' => 'array',
        'actions' => 'array',
        'meta' => 'array',
        'workflow_history' => 'array',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function budgetVersion(): BelongsTo
    {
        return $this->belongsTo(BudgetVersion::class, 'budget_version_id');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BudgetScenario::class, 'scenario_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WipForecastLine::class, 'forecast_version_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(WipForecastAdjustment::class, 'forecast_version_id');
    }

    public function assumptions(): HasMany
    {
        return $this->hasMany(WipForecastAssumption::class, 'forecast_version_id');
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(WipForecastAuditEvent::class, 'forecast_version_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
