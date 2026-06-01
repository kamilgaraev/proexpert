<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignVersionStatusEnum: string
{
    case UPLOADED = 'uploaded';
    case CURRENT = 'current';
    case SUPERSEDED = 'superseded';
    case ARCHIVED = 'archived';
}
