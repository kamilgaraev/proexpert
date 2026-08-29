<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Enums;

enum ProcurementAwardSelectionScope: string
{
    case SUPPLIER_REQUEST = 'supplier_request';
    case PURCHASE_REQUEST = 'purchase_request';
}
