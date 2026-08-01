<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\Concerns\RejectsPayrollReadinessSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class PayrollReadinessSnapshotRecord extends Model
{
    use RejectsPayrollReadinessSourceMutation;

    public $timestamps = false;

    protected $table = 'workforce_payroll_readiness_snapshots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'actor_user_id' => 'integer',
            'source_row_count' => 'integer',
            'validation_issue_count' => 'integer',
            'blocker_count' => 'integer',
            'item_count' => 'integer',
            'policy_definition' => 'array',
            'blocker_codes' => 'array',
            'gap_codes' => 'array',
            'evaluated_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'sealed_at' => 'immutable_datetime',
        ];
    }
}
