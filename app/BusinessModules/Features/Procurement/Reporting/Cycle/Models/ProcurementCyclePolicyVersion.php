<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Cycle\Models;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\Concerns\RejectsProcurementSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class ProcurementCyclePolicyVersion extends Model
{
    use RejectsProcurementSourceMutation;

    public const UPDATED_AT = null;

    protected $table = 'procurement_cycle_policy_versions';

    protected $guarded = ['id'];

    protected $casts = [
        'weekly_windows' => 'array',
        'exceptions' => 'array',
        'stage_sla_seconds' => 'array',
        'terminal_cancellation_policy' => 'array',
        'effective_from' => 'immutable_datetime',
        'effective_to' => 'immutable_datetime',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
