<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportPublicationFeatureMode: string
{
    case OFF = 'off';
    case CANARY = 'canary';
    case ON = 'on';
    case DISABLED = 'disabled';
}
