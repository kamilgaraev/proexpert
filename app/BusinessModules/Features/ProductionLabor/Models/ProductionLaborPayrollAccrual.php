<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Models;

use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionLaborPayrollAccrual extends Model
{
    use SoftDeletes;

    protected $table = 'production_labor_payroll_accruals';

    protected $fillable = [
        'organization_id',
        'work_order_id',
        'work_order_line_id',
        'project_id',
        'schedule_task_id',
        'period_start',
        'period_end',
        'accepted_quantity',
        'accepted_hours',
        'amount',
        'status',
        'approved_at',
        'approved_by_user_id',
        'payment_payload',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'accepted_quantity' => 'decimal:4',
        'accepted_hours' => 'decimal:2',
        'amount' => 'decimal:2',
        'approved_at' => 'datetime',
        'payment_payload' => 'array',
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

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
