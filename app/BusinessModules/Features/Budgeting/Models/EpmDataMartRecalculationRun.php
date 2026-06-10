<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EpmDataMartRecalculationRun extends BudgetingModel
{
    protected $fillable = [
        'uuid',
        'organization_id',
        'report_scope',
        'scope_hash',
        'active_lock',
        'status',
        'formula_version',
        'source_hash',
        'snapshot_id',
        'filters',
        'source_refs',
        'error_summary',
        'requested_by',
        'queued_at',
        'started_at',
        'finished_at',
        'generated_at',
        'duration_ms',
        'attempts_count',
    ];

    protected $casts = [
        'organization_id' => 'integer',
        'snapshot_id' => 'integer',
        'filters' => 'array',
        'source_refs' => 'array',
        'error_summary' => 'array',
        'requested_by' => 'integer',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'generated_at' => 'datetime',
        'duration_ms' => 'integer',
        'attempts_count' => 'integer',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EpmDataMartSnapshot::class, 'snapshot_id');
    }

    public function scopeForScope(Builder $query, int $organizationId, string $reportScope, string $scopeHash): Builder
    {
        return $query
            ->where('organization_id', $organizationId)
            ->where('report_scope', $reportScope)
            ->where('scope_hash', $scopeHash);
    }
}
