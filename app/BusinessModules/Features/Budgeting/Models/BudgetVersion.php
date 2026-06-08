<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class BudgetVersion extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'budget_period_id',
        'scenario_id',
        'budget_kind',
        'version_number',
        'name',
        'description',
        'status',
        'submitted_at',
        'approved_at',
        'activated_at',
        'workflow_history',
        'created_by',
        'submitted_by',
        'approved_by',
        'activated_by',
        'updated_by',
    ];

    protected $casts = [
        'version_number' => 'integer',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'activated_at' => 'datetime',
        'workflow_history' => 'array',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(BudgetPeriod::class, 'budget_period_id');
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(BudgetScenario::class, 'scenario_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class, 'budget_version_id');
    }

    public function importBatches(): HasMany
    {
        return $this->hasMany(BudgetImportBatch::class, 'budget_version_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
