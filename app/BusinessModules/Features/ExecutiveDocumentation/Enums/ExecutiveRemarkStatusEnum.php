<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\ExecutiveDocumentation\Enums;

enum ExecutiveRemarkStatusEnum: string
{
    case OPEN = 'open';
    case RESOLVED = 'resolved';
}
