<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Models;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\Models\Concerns\RejectsProcurementSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class ProcurementAwardPolicyVersion extends Model
{
    use RejectsProcurementSourceMutation;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $table = 'procurement_award_policy_versions';

    protected $guarded = [];

    protected $casts = [
        'version' => 'integer',
        'policy_payload' => 'array',
        'published_at' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
    ];
}
