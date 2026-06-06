<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignReviewCommentStatusEnum: string
{
    case OPEN = 'open';
    case ANSWERED = 'answered';
    case RESOLVED = 'resolved';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
}
