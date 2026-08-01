<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Models\Concerns\RejectsPayrollReadinessSourceMutation;
use Illuminate\Database\Eloquent\Model;

final class PayrollReadinessSnapshotItemRecord extends Model
{
    use RejectsPayrollReadinessSourceMutation;

    public $timestamps = false;

    protected $table = 'workforce_payroll_readiness_snapshot_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer',
            'lineage' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
