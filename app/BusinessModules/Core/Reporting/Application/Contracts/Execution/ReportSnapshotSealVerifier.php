<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Contracts\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportSnapshotSealVerificationInput;

interface ReportSnapshotSealVerifier
{
    public function assertTrusted(ReportSnapshotSealVerificationInput $input): void;
}
