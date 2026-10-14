<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrEvent extends Model
{
    public $timestamps = false;
    protected $table = 'workforce_hr_events';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'relation_id',
        'event_type',
        'effective_date',
        'basis_reference',
        'basis_document_id',
        'before_state',
        'after_state',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'before_state' => 'array',
        'after_state' => 'array',
        'recorded_at' => 'datetime',
    ];
}
