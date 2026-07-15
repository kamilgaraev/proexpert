<?php

declare(strict_types=1);

namespace App\Enums\Billing;

enum PackageAccessSource: string
{
    case Trial = 'trial';
    case PaidPackage = 'paid_package';
    case FullSuite = 'full_suite';
    case Corporate = 'corporate';
}
