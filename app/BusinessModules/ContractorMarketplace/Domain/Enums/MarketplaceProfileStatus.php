<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Enums;

enum MarketplaceProfileStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case PAUSED = 'paused';
    case BLOCKED = 'blocked';
}
