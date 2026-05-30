<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Http\Resources;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\Http\Resources\ModelJsonResource;
use Illuminate\Http\Request;

class MarketplaceContractorProfileResource extends ModelJsonResource
{
    public function toArray(Request $request): array
    {
        $profile = $this->typedResource(MarketplaceContractorProfile::class);

        return [
            'id' => $profile->id,
            'organization_id' => $profile->organization_id,
            'status' => $profile->status?->value,
            'display_name' => $profile->display_name,
            'short_description' => $profile->short_description,
            'description' => $profile->description,
            'team_size_min' => $profile->team_size_min,
            'team_size_max' => $profile->team_size_max,
            'years_on_market' => $profile->years_on_market,
            'base_city' => $profile->base_city,
            'service_radius_km' => $profile->service_radius_km,
            'availability_status' => $profile->availability_status,
            'available_from' => $profile->available_from?->toISOString(),
            'verification_level' => $profile->verification_level,
            'is_visible_in_marketplace' => $profile->is_visible_in_marketplace,
            'published_at' => $profile->published_at?->toISOString(),
            'metadata' => $profile->metadata ?? [],
            'organization' => $this->whenLoaded('organization', fn (): array => [
                'id' => $profile->organization->id,
                'name' => $profile->organization->name,
                'city' => $profile->organization->city,
                'is_verified' => $profile->organization->is_verified,
            ]),
            'categories' => $this->whenLoaded('categories', fn () => $profile->categories->map(static fn ($category): array => [
                'id' => $category->id,
                'category_id' => $category->category_id,
                'is_primary' => $category->is_primary,
                'experience_years' => $category->experience_years,
                'team_capacity' => $category->team_capacity,
                'min_project_budget' => $category->min_project_budget,
                'max_project_budget' => $category->max_project_budget,
                'rating_score' => $category->rating_score,
                'ratings_count' => $category->ratings_count,
                'completed_projects_count' => $category->completed_projects_count,
                'category' => $category->relationLoaded('category') && $category->category
                    ? [
                        'id' => $category->category->id,
                        'slug' => $category->category->slug,
                        'name' => $category->category->name,
                        'type' => $category->category->type?->value,
                    ]
                    : null,
            ])->values()->all()),
            'ratings' => $this->whenLoaded('ratings', fn () => $profile->ratings->map(static fn ($rating): array => [
                'id' => $rating->id,
                'category_id' => $rating->category_id,
                'score' => $rating->score,
                'quality_score' => $rating->quality_score,
                'deadline_score' => $rating->deadline_score,
                'communication_score' => $rating->communication_score,
                'safety_score' => $rating->safety_score,
                'financial_discipline_score' => $rating->financial_discipline_score,
                'reviews_count' => $rating->reviews_count,
                'completed_offers_count' => $rating->completed_offers_count,
                'repeat_hires_count' => $rating->repeat_hires_count,
                'last_recalculated_at' => $rating->last_recalculated_at?->toISOString(),
                'category' => $rating->relationLoaded('category') && $rating->category
                    ? [
                        'id' => $rating->category->id,
                        'slug' => $rating->category->slug,
                        'name' => $rating->category->name,
                        'type' => $rating->category->type?->value,
                    ]
                    : null,
            ])->values()->all()),
            'regions' => $this->whenLoaded('regions', fn () => $profile->regions->map(static fn ($region): array => [
                'id' => $region->id,
                'country' => $region->country,
                'region' => $region->region,
                'city' => $region->city,
                'is_primary' => $region->is_primary,
            ])->values()->all()),
            'portfolio_items' => $this->whenLoaded('portfolioItems', fn () => $profile->portfolioItems->map(static fn ($item): array => [
                'id' => $item->id,
                'category_id' => $item->category_id,
                'title' => $item->title,
                'description' => $item->description,
                'city' => $item->city,
                'completed_at' => $item->completed_at?->toISOString(),
                'media' => $item->media ?? [],
            ])->values()->all()),
            'documents' => $this->whenLoaded('documents', fn () => $profile->documents->map(static fn ($document): array => [
                'id' => $document->id,
                'type' => $document->type,
                'title' => $document->title,
                'status' => $document->status,
                'verified_at' => $document->verified_at?->toISOString(),
            ])->values()->all()),
            'created_at' => $profile->created_at?->toISOString(),
            'updated_at' => $profile->updated_at?->toISOString(),
        ];
    }
}
