<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Events;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\Models\User;

abstract class MarketplaceProfileEvent
{
    public function __construct(
        public readonly MarketplaceContractorProfile $profile,
        public readonly ?User $actor = null,
    ) {
    }
}
