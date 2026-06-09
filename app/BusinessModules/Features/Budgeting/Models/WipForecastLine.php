<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WipForecastLine extends BudgetingModel
{
    protected $fillable = [
        'uuid',
        'forecast_version_id',
        'organization_id',
        'project_id',
        'stage_id',
        'contract_id',
        'estimate_item_id',
        'period',
        'currency',
        'bac',
        'percent_complete',
        'ev',
        'pv',
        'ac',
        'wip_total',
        'ctc',
        'etc',
        'ftc',
        'eac',
        'forecast_revenue_at_completion',
        'forecast_gross_margin',
        'forecast_margin_percent',
        'cpi',
        'spi',
        'progress_source',
        'quality_status',
        'group_values',
        'dimensions',
        'problem_flags',
        'risk_flags',
        'source_row_refs',
        'formula_components',
        'comparison',
        'source_snapshot_hash',
    ];

    protected $casts = [
        'bac' => 'decimal:2',
        'percent_complete' => 'decimal:4',
        'ev' => 'decimal:2',
        'pv' => 'decimal:2',
        'ac' => 'decimal:2',
        'wip_total' => 'decimal:2',
        'ctc' => 'decimal:2',
        'etc' => 'decimal:2',
        'ftc' => 'decimal:2',
        'eac' => 'decimal:2',
        'forecast_revenue_at_completion' => 'decimal:2',
        'forecast_gross_margin' => 'decimal:2',
        'forecast_margin_percent' => 'decimal:4',
        'cpi' => 'decimal:6',
        'spi' => 'decimal:6',
        'group_values' => 'array',
        'dimensions' => 'array',
        'problem_flags' => 'array',
        'risk_flags' => 'array',
        'source_row_refs' => 'array',
        'formula_components' => 'array',
        'comparison' => 'array',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WipForecastVersion::class, 'forecast_version_id');
    }
}
