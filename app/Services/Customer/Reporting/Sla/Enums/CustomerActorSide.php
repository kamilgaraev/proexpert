<?php

declare(strict_types=1);

namespace App\Services\Customer\Reporting\Sla\Enums;

enum CustomerActorSide: string
{
    case CUSTOMER = 'customer';
    case DELIVERY_TEAM = 'delivery_team';
    case SYSTEM = 'system';
    case UNKNOWN = 'unknown';
}
