<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierProposalCurrencyEnum: string
{
    case RUB = 'RUB';
    case USD = 'USD';
    case EUR = 'EUR';
}
