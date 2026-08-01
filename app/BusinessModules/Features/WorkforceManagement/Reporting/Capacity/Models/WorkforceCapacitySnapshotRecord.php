<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\Concerns\RejectsWorkforceCapacityMutation;
use Illuminate\Database\Eloquent\Model;

final class WorkforceCapacitySnapshotRecord extends Model
{
    use RejectsWorkforceCapacityMutation;

    protected $table = 'workforce_capacity_snapshots';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'as_of_date' => 'date:Y-m-d',
        'month_start' => 'date:Y-m-d',
        'policy_definition' => 'array',
        'gap_codes' => 'array',
        'source_counts' => 'array',
        'captured_at' => 'immutable_datetime',
        'sealed_at' => 'immutable_datetime',
    ];
}
