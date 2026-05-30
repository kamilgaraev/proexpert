<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Resources;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\Http\Resources\ModelJsonResource;
use Illuminate\Http\Request;

class MarketplaceContractorListItemResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->typedResource(MarketplaceContractorProfile::class);
        $categoryId = $request->integer('category_id') ?: null;
        $selectedCategory = $categoryId
            ? $profile->categories->firstWhere('category_id', $categoryId)
            : $profile->categories->firstWhere('is_primary', true);
        $selectedRating = $categoryId
            ? $profile->ratings->firstWhere('category_id', $categoryId)
            : $profile->ratings->sortByDesc('score')->first();

        return [
            'id' => $profile->id,
            'organization_id' => $profile->organization_id,
            'display_name' => $profile->display_name,
            'short_description' => $profile->short_description,
            'base_city' => $profile->base_city,
            'availability_status' => $profile->availability_status,
            'verification_level' => $profile->verification_level,
            'team_size_min' => $profile->team_size_min,
            'team_size_max' => $profile->team_size_max,
            'published_at' => $profile->published_at?->toISOString(),
            'organization' => $this->whenLoaded('organization', fn (): array => [
                'id' => $profile->organization->id,
                'name' => $profile->organization->name,
                'city' => $profile->organization->city,
                'is_verified' => $profile->organization->is_verified,
            ]),
            'category_match' => $selectedCategory ? [
                'category_id' => $selectedCategory->category_id,
                'slug' => $selectedCategory->category?->slug,
                'name' => $selectedCategory->category?->name,
                'is_primary' => $selectedCategory->is_primary,
                'experience_years' => $selectedCategory->experience_years,
                'team_capacity' => $selectedCategory->team_capacity,
                'min_project_budget' => $selectedCategory->min_project_budget,
                'max_project_budget' => $selectedCategory->max_project_budget,
            ] : null,
            'category_rating' => $selectedRating ? [
                'category_id' => $selectedRating->category_id,
                'score' => $selectedRating->score,
                'reviews_count' => $selectedRating->reviews_count,
                'completed_offers_count' => $selectedRating->completed_offers_count,
                'repeat_hires_count' => $selectedRating->repeat_hires_count,
            ] : null,
        ];
    }
}
