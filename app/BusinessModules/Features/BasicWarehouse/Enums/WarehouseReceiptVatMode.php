<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Enums;

enum WarehouseReceiptVatMode: string
{
    case None = 'none';
    case Included = 'included';
    case OnTop = 'on_top';
}
