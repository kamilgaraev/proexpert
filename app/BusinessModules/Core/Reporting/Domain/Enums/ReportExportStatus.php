<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportExportStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case UPLOADING = 'uploading';
    case READY = 'ready';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
}
