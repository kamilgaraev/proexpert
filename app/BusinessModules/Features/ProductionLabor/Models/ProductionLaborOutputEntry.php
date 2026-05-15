<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Models;

use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionLaborOutputEntry extends Model
{
    use SoftDeletes;

    protected $table = 'production_labor_output_entries';

    protected $fillable = [
        'organization_id',
        'work_order_id',
        'work_order_line_id',
        'project_id',
        'schedule_task_id',
        'recorded_by_user_id',
        'approved_by_user_id',
        'work_date',
        'quantity',
        'hours',
        'status',
        'approved_at',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'work_date' => 'date',
        'quantity' => 'decimal:4',
        'hours' => 'decimal:2',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionLaborWorkOrder::class, 'work_order_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLaborWorkOrderLine::class, 'work_order_line_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
