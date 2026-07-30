<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportSavedViewStatus: string
{
    case ACTIVE = 'active';
    case NEEDS_MIGRATION = 'needs_migration';
}
