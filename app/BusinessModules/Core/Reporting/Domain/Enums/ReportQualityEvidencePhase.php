<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportQualityEvidencePhase: string
{
    case PLATFORM = 'platform';
    case RELEASE = 'release';
}
