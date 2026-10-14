<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Domain\HR\Models;

use Illuminate\Database\Eloquent\Model;

final class HrCandidateEvent extends Model
{
    public $timestamps = false;
    protected $table = 'workforce_hr_candidate_events';

    protected $fillable = [
        'organization_id',
        'candidate_id',
        'from_stage',
        'to_stage',
        'reason',
        'recorded_by',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
    ];
}
