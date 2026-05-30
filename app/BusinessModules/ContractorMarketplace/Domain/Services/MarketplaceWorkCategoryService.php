<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use Illuminate\Database\Eloquent\Collection;

class MarketplaceWorkCategoryService
{
    /**
     * @return Collection<int, MarketplaceWorkCategory>
     */
    public function activeTree(): Collection
    {
        return MarketplaceWorkCategory::query()
            ->active()
            ->roots()
            ->with(['activeChildren' => fn ($query) => $query->orderBy('sort_order')->orderBy('name')])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, MarketplaceWorkCategory>
     */
    public function activeOptions(): Collection
    {
        return MarketplaceWorkCategory::query()
            ->active()
            ->orderBy('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }
}
