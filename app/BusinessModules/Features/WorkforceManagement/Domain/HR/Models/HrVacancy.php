<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrVacancy extends Model
{
    protected $table = 'workforce_hr_vacancies';

    protected $fillable = [
        'organization_id',
        'title',
        'status',
        'owner_id',
        'target_date',
        'stage_pipeline',
    ];

    protected $casts = [
        'stage_pipeline' => 'array',
        'target_date' => 'date:Y-m-d',
    ];
}
