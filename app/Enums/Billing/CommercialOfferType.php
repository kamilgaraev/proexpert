<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum CommercialOfferType: string
{
    case Packages = 'packages';
    case FullSuite = 'full_suite';
    case Corporate = 'corporate';
}
