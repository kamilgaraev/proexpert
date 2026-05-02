<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierProposalVatModeEnum: string
{
    case INCLUDED = 'included';
    case EXCLUDED = 'excluded';
    case NOT_APPLICABLE = 'not_applicable';
}
