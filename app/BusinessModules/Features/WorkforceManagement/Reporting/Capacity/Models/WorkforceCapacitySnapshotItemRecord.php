<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\Models\Concerns\RejectsWorkforceCapacityMutation;
use Illuminate\Database\Eloquent\Model;

final class WorkforceCapacitySnapshotItemRecord extends Model
{
    use RejectsWorkforceCapacityMutation;

    protected $table = 'workforce_capacity_snapshot_items';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'lineage' => 'array',
        'evidence' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
