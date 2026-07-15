<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum CommercialAccountStatus: string
{
    case Free = 'free';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Corporate = 'corporate';
}
