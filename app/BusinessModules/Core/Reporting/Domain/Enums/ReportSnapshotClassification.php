<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSnapshotClassification: string
{
    case OPERATIONAL = 'operational';
    case OFFICIAL = 'official';
}
