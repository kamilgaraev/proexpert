<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\SafetyManagement\Reporting\Admission\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SafetyAdmissionPolicyVersion extends Model
{
    protected $table = 'safety_admission_policy_versions';

    protected $guarded = [];

    protected $casts = [
        'effective_from' => 'immutable_date',
        'effective_until' => 'immutable_date',
        'mandatory_requirements' => 'array',
        'expiring_soon_days' => 'integer',
        'waiver_evidence_required' => 'boolean',
    ];

    protected static function booted(): void
    {
        self::updating(static fn (): never => throw new LogicException('safety_admission_policy_version_immutable'));
        self::deleting(static fn (): never => throw new LogicException('safety_admission_policy_version_immutable'));
    }
}
