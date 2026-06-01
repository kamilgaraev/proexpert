<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignDerivativeStatusEnum: string
{
    case MISSING = 'missing';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case FAILED = 'failed';
}
