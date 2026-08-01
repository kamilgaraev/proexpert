<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

enum ReportDispatchAggregate: string
{
    case RUN = 'run';
    case EXPORT = 'export';
}
