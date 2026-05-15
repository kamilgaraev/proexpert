<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Models;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionLaborTimesheet extends Model
{
    use SoftDeletes;

    protected $table = 'production_labor_timesheets';

    protected $fillable = [
        'organization_id',
        'work_order_id',
        'project_id',
        'created_by_user_id',
        'shift_date',
        'status',
        'metadata',
    ];

    protected $casts = [
        'shift_date' => 'date',
        'metadata' => 'array',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(ProductionLaborWorkOrder::class, 'work_order_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ProductionLaborTimesheetEntry::class, 'timesheet_id');
    }
}
