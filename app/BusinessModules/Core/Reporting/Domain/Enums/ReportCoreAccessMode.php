<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportCoreAccessMode: string
{
    case REPORTING_WORKSPACE = 'reporting_workspace';
    case SOURCE_MODULE_REPORT = 'source_module_report';
}
