<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSnapshotIdentityViolationReason: string
{
    case INVALID_KIND = 'invalid_kind';
    case INVALID_ID = 'invalid_id';
    case OFFICIAL_SEAL_REQUIRED = 'official_seal_required';
    case OPERATIONAL_SEAL_FORBIDDEN = 'operational_seal_forbidden';
    case SEAL_TIME_INVALID = 'seal_time_invalid';
}
