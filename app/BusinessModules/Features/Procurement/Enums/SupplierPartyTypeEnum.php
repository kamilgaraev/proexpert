<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierPartyTypeEnum: string
{
    case REGISTERED = 'registered';
    case EXTERNAL = 'external';
}
