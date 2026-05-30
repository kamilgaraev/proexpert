<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Resources;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Http\Resources\ModelJsonResource;
use Illuminate\Http\Request;

class MarketplaceWorkCategoryResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $category = $this->typedResource(MarketplaceWorkCategory::class);

        return [
            'id' => $category->id,
            'parent_id' => $category->parent_id,
            'slug' => $category->slug,
            'name' => $category->name,
            'type' => $category->type?->value,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'children' => $category->relationLoaded('activeChildren')
                ? MarketplaceWorkCategoryResource::collection($category->activeChildren)
                : [],
        ];
    }
}
