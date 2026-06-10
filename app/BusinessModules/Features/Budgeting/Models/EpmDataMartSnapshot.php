<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class EpmDataMartSnapshot extends BudgetingModel
{
    protected $fillable = [
        'uuid',
        'organization_id',
        'report_scope',
        'scope_hash',
        'status',
        'formula_version',
        'source_hash',
        'period_start',
        'period_end',
        'as_of_date',
        'project_id',
        'currency',
        'filters',
        'payload',
        'freshness',
        'source_refs',
        'generated_at',
        'stale_at',
        'superseded_at',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'as_of_date' => 'date',
        'filters' => 'array',
        'payload' => 'array',
        'freshness' => 'array',
        'source_refs' => 'array',
        'generated_at' => 'datetime',
        'stale_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];

    public function aggregates(): HasMany
    {
        return $this->hasMany(EpmDataMartAggregate::class, 'snapshot_id');
    }

    public function scopeForScope(Builder $query, int $organizationId, string $reportScope, string $scopeHash): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where('report_scope', $reportScope)
            ->where('scope_hash', $scopeHash);
    }
}
