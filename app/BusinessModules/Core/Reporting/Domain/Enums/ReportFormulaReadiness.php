<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportFormulaReadiness: string
{
    case READY = 'ready';
    case CONTRACT_REQUIRED = 'contract_required';
    case POLICY_REQUIRED = 'policy_required';
    case BLOCKED_BY_SOURCE = 'blocked_by_source';
}
