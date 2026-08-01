<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\Contracts;

use App\BusinessModules\Features\WorkforceManagement\Reporting\PayrollReadiness\DTO\PayrollReadinessPeriodIdentity;

interface PayrollReadinessEvidenceSource
{
    public function sourceRows(PayrollReadinessPeriodIdentity $period): iterable;

    public function validationIssues(PayrollReadinessPeriodIdentity $period): iterable;
}
