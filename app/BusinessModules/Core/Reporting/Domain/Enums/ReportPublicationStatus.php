<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportPublicationStatus: string
{
    case PUBLISHED = 'published';
    case DISABLED = 'disabled';
}
