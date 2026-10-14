<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrCandidate extends Model
{
    protected $table = 'workforce_hr_candidates';

    protected $fillable = [
        'organization_id',
        'vacancy_id',
        'name',
        'email',
        'phone',
        'stage',
        'owner_id',
    ];

    protected $casts = [

    ];
}
