<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\QualityControl\Reporting\DefectFlow\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class QualityDefectFlowPolicyVersion extends Model
{
    protected $table = 'quality_defect_flow_policy_versions';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_until' => 'immutable_date',
        'terminal_statuses' => 'array',
        'maturity_days' => 'integer',
        'sla_days' => 'integer',
        'closure_evidence_required' => 'boolean',
        'severity_weights' => 'array',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('quality_defect_flow_policy_version_immutable'));
        self::deleting(static fn (): never => throw new LogicException('quality_defect_flow_policy_version_immutable'));
    }
}
