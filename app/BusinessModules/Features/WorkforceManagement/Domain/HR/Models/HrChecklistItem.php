<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrChecklistItem extends Model
{
    protected $table = 'workforce_hr_checklist_items';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'lifecycle',
        'title',
        'owner_id',
        'due_date',
        'completed_at',
        'completed_by',
        'completion_reference',
    ];

    protected $casts = [
        'due_date' => 'date:Y-m-d',
        'completed_at' => 'datetime',
    ];
}
