<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportOperation: string
{
    case VIEW = 'view';
    case RUN = 'run';
    case EXPORT = 'export';
    case DOWNLOAD = 'download';
    case MANAGE = 'manage';
    case VIEW_SENSITIVE = 'view_sensitive';
    case VIEW_AUDIT = 'view_audit';
    case DRILL_DOWN = 'drill_down';
}
