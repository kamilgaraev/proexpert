<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class EmploymentContract extends Model
{
    use \Illuminate\Database\Eloquent\SoftDeletes;
    protected $table = 'workforce_employment_contracts';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'contract_number',
        'contract_date',
        'start_date',
        'end_date',
        'status',
        'metadata',
        'relation_type',
        'employment_mode',
        'job_share',
        'timezone',
        'weekly_norm_hours',
        'summed_accounting_period',
        'calendar_profile',
        'revision',
        'supersedes_id',
        'created_by',
    ];

    protected $casts = [
        'contract_date' => 'date:Y-m-d',
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'metadata' => 'array',
        'job_share' => 'decimal:3',
        'weekly_norm_hours' => 'decimal:2',
    ];
}
