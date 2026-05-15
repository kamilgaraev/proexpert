<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ProductionLabor\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionLaborTimesheetEntry extends Model
{
    use SoftDeletes;

    protected $table = 'production_labor_timesheet_entries';

    protected $fillable = [
        'organization_id',
        'timesheet_id',
        'work_order_line_id',
        'user_id',
        'worker_name',
        'hours',
        'safety_permit_reference',
        'metadata',
    ];

    protected $casts = [
        'hours' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function timesheet(): BelongsTo
    {
        return $this->belongsTo(ProductionLaborTimesheet::class, 'timesheet_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ProductionLaborWorkOrderLine::class, 'work_order_line_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
