<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class EpmDataMartAggregate extends BudgetingModel
{
    protected $fillable = [
        'uuid',
        'snapshot_id',
        'organization_id',
        'report_scope',
        'scope_hash',
        'aggregate_key',
        'formula_version',
        'source_hash',
        'period_start',
        'period_end',
        'as_of_date',
        'project_id',
        'currency',
        'dimensions',
        'metrics',
        'source_refs',
        'generated_at',
    ];

    protected $casts = [
        'snapshot_id' => 'integer',
        'organization_id' => 'integer',
        'project_id' => 'integer',
        'period_start' => 'date',
        'period_end' => 'date',
        'as_of_date' => 'date',
        'dimensions' => 'array',
        'metrics' => 'array',
        'source_refs' => 'array',
        'generated_at' => 'datetime',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(EpmDataMartSnapshot::class, 'snapshot_id');
    }
}
