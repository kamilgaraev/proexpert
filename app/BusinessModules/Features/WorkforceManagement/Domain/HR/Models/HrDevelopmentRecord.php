<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrDevelopmentRecord extends Model
{
    protected $table = 'workforce_hr_development_records';

    protected $fillable = [
        'organization_id',
        'employee_id',
        'record_type',
        'title',
        'owner_id',
        'effective_date',
        'valid_until',
        'result',
        'source_file_id',
        'supersedes_id',
        'recorded_by',
    ];

    protected $casts = [
        'effective_date' => 'date:Y-m-d',
        'valid_until' => 'date:Y-m-d',
    ];
}
