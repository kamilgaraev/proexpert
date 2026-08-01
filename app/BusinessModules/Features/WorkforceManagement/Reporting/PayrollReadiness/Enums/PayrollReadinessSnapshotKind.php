<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Enums;

enum PayrollReadinessSnapshotKind: string
{
    case PRE_LOCK_BLOCKED = 'pre_lock_blocked';
    case LOCK_SUCCEEDED = 'lock_succeeded';
}
