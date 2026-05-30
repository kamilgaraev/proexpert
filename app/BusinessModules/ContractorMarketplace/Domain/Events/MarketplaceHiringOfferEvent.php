<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Events;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\Models\User;

abstract class MarketplaceHiringOfferEvent
{
    public function __construct(
        public readonly MarketplaceHiringOffer $offer,
        public readonly ?User $actor = null,
    ) {
    }
}
