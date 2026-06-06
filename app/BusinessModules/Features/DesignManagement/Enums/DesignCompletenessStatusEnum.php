<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignCompletenessStatusEnum: string
{
    case READY = 'ready';
    case WARNING = 'warning';
    case BLOCKED = 'blocked';
}
