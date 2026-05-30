<?php

declare(strict_types=1);

namespace Tests\Unit\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceRatingService;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketplaceRatingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_recalculate_stores_category_rating_snapshot(): void
    {
        $profile = $this->createProfileWithCategory('monolith', [
            'rating_score' => 4.0,
            'ratings_count' => 2,
            'completed_projects_count' => 3,
        ]);
        $category = MarketplaceWorkCategory::query()->where('slug', 'monolith')->firstOrFail();

        $rating = app(MarketplaceRatingService::class)->recalculateForCategory(
            $profile->id,
            $category->id,
            [
                'deadline_score' => 4.5,
                'communication_score' => 5.0,
                'repeat_hires_count' => 2,
            ]
        );

        $this->assertSame($profile->id, $rating->profile_id);
        $this->assertSame($category->id, $rating->category_id);
        $this->assertSame(2, $rating->reviews_count);
        $this->assertSame(3, $rating->completed_offers_count);
        $this->assertSame(2, $rating->repeat_hires_count);
        $this->assertGreaterThan(4.0, (float) $rating->score);
        $this->assertSame($profile->categories()->first()->id, $rating->source_snapshot['capability_id']);
    }

    public function test_unrated_category_is_explicitly_stored_without_score(): void
    {
        $profile = $this->createProfileWithCategory('plumbing');
        $category = MarketplaceWorkCategory::query()->where('slug', 'plumbing')->firstOrFail();

        $rating = app(MarketplaceRatingService::class)->recalculateForCategory($profile->id, $category->id);

        $this->assertNull($rating->score);
        $this->assertSame(0, $rating->reviews_count);
        $this->assertSame(0, $rating->completed_offers_count);
        $this->assertSame(0, $rating->repeat_hires_count);
    }

    private function createProfileWithCategory(string $categorySlug, array $categoryAttributes = []): MarketplaceContractorProfile
    {
        $organization = Organization::factory()->verified()->create();
        $profile = MarketplaceContractorProfile::query()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'display_name' => 'Rated Contractor',
            'base_city' => 'Казань',
            'availability_status' => 'available',
            'verification_level' => 'basic',
            'is_visible_in_marketplace' => true,
            'published_at' => now(),
            'metadata' => [],
        ]);
        $category = MarketplaceWorkCategory::query()->where('slug', $categorySlug)->firstOrFail();

        $profile->categories()->create(array_merge([
            'category_id' => $category->id,
            'is_primary' => true,
        ], $categoryAttributes));

        return $profile;
    }
}
