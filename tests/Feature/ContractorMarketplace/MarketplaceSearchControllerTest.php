<?php

declare(strict_types=1);

namespace Tests\Feature\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ContractorType;
use App\Models\Contractor;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MarketplaceSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_returns_only_visible_profiles_from_closed_network(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $connectedOrganization = Organization::factory()->verified()->create(['name' => 'Connected Contractor']);
        $unrelatedOrganization = Organization::factory()->verified()->create(['name' => 'Unrelated Contractor']);
        $category = MarketplaceWorkCategory::query()->where('slug', 'monolith')->firstOrFail();

        $this->connectOrganization($context->organization, $connectedOrganization);
        $connectedProfile = $this->createVisibleProfile($connectedOrganization, 'Монолит Казань', $category->id, 4.8);
        $this->createVisibleProfile($unrelatedOrganization, 'Монолит Чужой', $category->id, 5.0);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/search?category_id={$category->id}&sort_by=category_rating");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $connectedProfile->id);
        $response->assertJsonPath('data.0.category_match.category_id', $category->id);
        $response->assertJsonPath('data.0.category_rating.score', '4.80');
        $response->assertJsonPath('summary.network_size', 1);
    }

    public function test_search_filters_by_availability_city_rating_and_budget(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $category = MarketplaceWorkCategory::query()->where('slug', 'electrical')->firstOrFail();
        $matchingOrganization = Organization::factory()->verified()->create();
        $lowRatingOrganization = Organization::factory()->verified()->create();
        $this->connectOrganization($context->organization, $matchingOrganization);
        $this->connectOrganization($context->organization, $lowRatingOrganization);
        $matchingProfile = $this->createVisibleProfile($matchingOrganization, 'Электро Плюс', $category->id, 4.6, [
            'base_city' => 'Москва',
            'availability_status' => 'available',
            'min_project_budget' => 100000,
            'max_project_budget' => 1000000,
        ]);
        $this->createVisibleProfile($lowRatingOrganization, 'Электро Минус', $category->id, 3.2, [
            'base_city' => 'Москва',
            'availability_status' => 'available',
            'min_project_budget' => 100000,
            'max_project_budget' => 1000000,
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/search?category_id={$category->id}&city=Москва&availability_status=available&min_rating=4&budget_min=200000&budget_max=900000");

        $response->assertOk();
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $matchingProfile->id);
    }

    public function test_search_uses_bindings_for_relevance_query(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/search?search=%25' OR 1=1 --");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_show_returns_full_visible_profile_from_closed_network(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $connectedOrganization = Organization::factory()->verified()->create(['name' => 'Detail Contractor']);
        $category = MarketplaceWorkCategory::query()->where('slug', 'installation')->firstOrFail();

        $this->connectOrganization($context->organization, $connectedOrganization);
        $profile = $this->createVisibleProfile($connectedOrganization, 'Install Pro', $category->id, 4.7);
        $profile->regions()->create([
            'country' => 'Russia',
            'region' => 'Tatarstan',
            'city' => 'Kazan',
            'is_primary' => true,
        ]);
        $profile->portfolioItems()->create([
            'category_id' => $category->id,
            'title' => 'Business Center',
            'city' => 'Kazan',
            'completed_at' => now(),
            'media' => [],
            'metadata' => [],
        ]);
        $profile->documents()->create([
            'type' => 'license',
            'title' => 'SRO',
            'file_path' => 'org-' . $connectedOrganization->id . '/marketplace/sro.pdf',
            'status' => 'verified',
            'verified_at' => now(),
            'metadata' => [],
        ]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/profiles/{$profile->id}");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.id', $profile->id);
        $response->assertJsonPath('data.organization.id', $connectedOrganization->id);
        $response->assertJsonPath('data.categories.0.category_id', $category->id);
        $response->assertJsonPath('data.ratings.0.score', '4.70');
        $response->assertJsonPath('data.regions.0.city', 'Kazan');
        $response->assertJsonPath('data.portfolio_items.0.title', 'Business Center');
        $response->assertJsonPath('data.documents.0.status', 'verified');
    }

    public function test_show_rejects_profile_outside_closed_network(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $category = MarketplaceWorkCategory::query()->where('slug', 'monolith')->firstOrFail();
        $unrelatedOrganization = Organization::factory()->verified()->create();
        $profile = $this->createVisibleProfile($unrelatedOrganization, 'External profile', $category->id, 5.0);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/profiles/{$profile->id}");

        $response->assertNotFound();
        $response->assertJsonPath('success', false);
    }

    public function test_show_rejects_hidden_or_inactive_profile(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $category = MarketplaceWorkCategory::query()->where('slug', 'monolith')->firstOrFail();
        $hiddenOrganization = Organization::factory()->verified()->create();
        $pausedOrganization = Organization::factory()->verified()->create();

        $this->connectOrganization($context->organization, $hiddenOrganization);
        $this->connectOrganization($context->organization, $pausedOrganization);
        $hiddenProfile = $this->createVisibleProfile($hiddenOrganization, 'Hidden profile', $category->id, 4.2, [
            'is_visible_in_marketplace' => false,
        ]);
        $pausedProfile = $this->createVisibleProfile($pausedOrganization, 'Paused profile', $category->id, 4.4, [
            'status' => 'paused',
        ]);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/profiles/{$hiddenProfile->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);

        $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/contractor-marketplace/profiles/{$pausedProfile->id}")
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    private function allowPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function connectOrganization(Organization $organization, Organization $sourceOrganization): void
    {
        Contractor::query()->create([
            'organization_id' => $organization->id,
            'source_organization_id' => $sourceOrganization->id,
            'name' => $sourceOrganization->name,
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
            'connected_at' => now(),
        ]);
    }

    private function createVisibleProfile(
        Organization $organization,
        string $name,
        int $categoryId,
        float $rating,
        array $overrides = []
    ): MarketplaceContractorProfile {
        $profile = MarketplaceContractorProfile::query()->create([
            'organization_id' => $organization->id,
            'status' => $overrides['status'] ?? 'active',
            'display_name' => $name,
            'short_description' => 'Проверенная команда',
            'description' => 'Проверенная команда для работ marketplace.',
            'team_size_min' => 3,
            'team_size_max' => 15,
            'base_city' => $overrides['base_city'] ?? 'Казань',
            'availability_status' => $overrides['availability_status'] ?? 'available',
            'verification_level' => 'basic',
            'is_visible_in_marketplace' => $overrides['is_visible_in_marketplace'] ?? true,
            'published_at' => $overrides['published_at'] ?? now(),
            'metadata' => [],
        ]);
        $profile->categories()->create([
            'category_id' => $categoryId,
            'is_primary' => true,
            'experience_years' => 5,
            'team_capacity' => 15,
            'min_project_budget' => $overrides['min_project_budget'] ?? 500000,
            'max_project_budget' => $overrides['max_project_budget'] ?? 5000000,
            'rating_score' => $rating,
            'ratings_count' => 3,
            'completed_projects_count' => 4,
        ]);
        $profile->ratings()->create([
            'category_id' => $categoryId,
            'score' => $rating,
            'quality_score' => $rating,
            'reviews_count' => 3,
            'completed_offers_count' => 4,
            'repeat_hires_count' => 1,
            'last_recalculated_at' => now(),
            'source_snapshot' => [],
        ]);

        return $profile;
    }
}
