<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Domain\Enums;

enum ReportPublicationReadiness: string
{
    case DRAFT = 'draft';
    case CANDIDATE = 'candidate';
    case PUBLISHED = 'published';
    case BLOCKED = 'blocked';
}
