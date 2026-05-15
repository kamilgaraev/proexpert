<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Models;

use App\Models\EstimateItem;
use App\Models\ScheduleTask;
use App\Models\WorkType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionLaborWorkOrderLine extends Model
{
    use SoftDeletes;

    protected $table = 'production_labor_work_order_lines';

    protected $fillable = [
        'organization_id',
        'work_order_id',
        'work_type_id',
        'estimate_item_id',
        'schedule_task_id',
        'name',
        'unit',
        'planned_quantity',
        'accepted_quantity',
        'unit_rate',
        'planned_hours',
        'hour_rate',
        'pay_basis',
        'requires_safety_permit',
        'metadata',
    ];

    protected $casts = [
        'planned_quantity' => 'decimal:4',
        'accepted_quantity' => 'decimal:4',
        'unit_rate' => 'decimal:2',
        'planned_hours' => 'decimal:2',
        'hour_rate' => 'decimal:2',
        'requires_safety_permit' => 'boolean',
        'metadata' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionLaborWorkOrder::class, 'work_order_id');
    }

    public function workType(): BelongsTo
    {
        return $this->belongsTo(WorkType::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function outputEntries(): HasMany
    {
        return $this->hasMany(ProductionLaborOutputEntry::class, 'work_order_line_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
