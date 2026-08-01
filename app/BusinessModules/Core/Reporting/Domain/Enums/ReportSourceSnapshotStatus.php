<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSourceSnapshotStatus: string
{
    case WRITING = 'writing';
    case READY = 'ready';
    case EXPIRED = 'expired';
}
