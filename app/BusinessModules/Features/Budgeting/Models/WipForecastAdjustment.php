<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Models;

use App\BusinessModules\Features\Budgeting\DTOs\WipForecastManualAdjustment;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class WipForecastAdjustment extends BudgetingModel
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'forecast_version_id',
        'organization_id',
        'scope',
        'scope_id',
        'project_id',
        'stage_id',
        'contract_id',
        'estimate_item_id',
        'period',
        'adjustment_type',
        'formula_component',
        'amount',
        'percent',
        'currency',
        'reason',
        'owner_user_id',
        'status',
        'valid_from',
        'valid_until',
        'affects_formulas',
        'source_snapshot_hash',
        'approved_by',
        'rejected_by',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'percent' => 'decimal:4',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'affects_formulas' => 'array',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(WipForecastVersion::class, 'forecast_version_id');
    }

    public function toManualAdjustment(): WipForecastManualAdjustment
    {
        return new WipForecastManualAdjustment(
            periodMonth: $this->period,
            projectId: $this->project_id === null ? null : (int) $this->project_id,
            stageId: $this->stage_id === null ? null : (int) $this->stage_id,
            contractId: $this->contract_id === null ? null : (int) $this->contract_id,
            estimateItemId: $this->estimate_item_id === null ? null : (int) $this->estimate_item_id,
            currency: mb_strtoupper((string) $this->currency),
            formulaComponent: (string) $this->formula_component,
            amount: (float) $this->amount,
            reason: $this->reason,
            status: (string) $this->status,
        );
    }
}
