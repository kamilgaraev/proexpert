<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Enums;

enum ExecutiveRemarkStatusEnum: string
{
    case OPEN = 'open';
    case ANSWERED = 'answered';
    case RETURNED = 'returned';
    case RESOLVED = 'resolved';
}
