<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Enums;

enum DesignObjectTypeEnum: string
{
    case NON_LINEAR_PRODUCTION = 'non_linear_production';
    case NON_LINEAR_NON_PRODUCTION = 'non_linear_non_production';
    case LINEAR = 'linear';
    case ORGANIZATION_CUSTOM = 'organization_custom';
}
