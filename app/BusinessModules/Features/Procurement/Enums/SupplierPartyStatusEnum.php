<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Enums;

enum SupplierPartyStatusEnum: string
{
    case DRAFT = 'draft';
    case REQUESTED = 'requested';
    case RESPONDED = 'responded';
    case SELECTED = 'selected';
    case REJECTED = 'rejected';
    case LINKED = 'linked';
}
