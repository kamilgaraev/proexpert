<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Models;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ScheduleTask;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionLaborWorkOrder extends Model
{
    use SoftDeletes;

    protected $table = 'production_labor_work_orders';

    protected $fillable = [
        'organization_id',
        'project_id',
        'schedule_task_id',
        'contractor_id',
        'created_by_user_id',
        'accepted_by_user_id',
        'order_number',
        'title',
        'assignee_type',
        'assignee_name',
        'planned_start_date',
        'planned_finish_date',
        'status',
        'issued_at',
        'submitted_at',
        'accepted_at',
        'closed_at',
        'return_reason',
        'metadata',
    ];

    protected $casts = [
        'planned_start_date' => 'date',
        'planned_finish_date' => 'date',
        'issued_at' => 'datetime',
        'submitted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'closed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scheduleTask(): BelongsTo
    {
        return $this->belongsTo(ScheduleTask::class);
    }

    public function contractor(): BelongsTo
    {
        return $this->belongsTo(Contractor::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function acceptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ProductionLaborWorkOrderLine::class, 'work_order_id');
    }

    public function outputEntries(): HasMany
    {
        return $this->hasMany(ProductionLaborOutputEntry::class, 'work_order_id');
    }

    public function timesheets(): HasMany
    {
        return $this->hasMany(ProductionLaborTimesheet::class, 'work_order_id');
    }

    public function payrollAccruals(): HasMany
    {
        return $this->hasMany(ProductionLaborPayrollAccrual::class, 'work_order_id');
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }
}
