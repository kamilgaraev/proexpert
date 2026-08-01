<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Dispatch;

enum ReportDispatchTopic: string
{
    case MATERIALIZE_RUN = 'materialize_run';
    case GENERATE_EXPORT = 'generate_export';
}
