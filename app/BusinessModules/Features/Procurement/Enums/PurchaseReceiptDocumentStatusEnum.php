<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum PurchaseReceiptDocumentStatusEnum: string
{
    case VALIDATED = 'validated';
    case ATTACHED = 'attached';
}
