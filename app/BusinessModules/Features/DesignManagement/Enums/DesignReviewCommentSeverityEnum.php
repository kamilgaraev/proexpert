<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignReviewCommentSeverityEnum: string
{
    case INFO = 'info';
    case WARNING = 'warning';
    case BLOCKING = 'blocking';
}
