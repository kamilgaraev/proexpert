<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Exceptions;

use App\BusinessModules\Core\Reporting\Domain\Enums\ReportSnapshotIdentityViolationReason;
use InvalidArgumentException;

final class ReportSnapshotIdentityViolation extends InvalidArgumentException
{
    public function __construct(
        public readonly ReportSnapshotIdentityViolationReason $reason,
    ) {
        parent::__construct('snapshot_identity_invalid');
    }
}
