<?php

declare(strict_types=1);

namespace Tests\Feature\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Domain\Authorization\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MarketplaceCategoryControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_endpoint_returns_seeded_active_tree(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/contractor-marketplace/categories');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.0.slug', 'construction');

        $slugs = collect($response->json('data'))
            ->flatMap(static fn (array $category): array => array_merge(
                [$category['slug']],
                array_column($category['children'] ?? [], 'slug')
            ))
            ->values()
            ->all();

        $this->assertContains('monolith', $slugs);
        $this->assertContains('electrical', $slugs);
        $this->assertContains('plumbing', $slugs);
    }

    public function test_categories_endpoint_hides_inactive_categories(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        MarketplaceWorkCategory::query()->where('slug', 'monolith')->update(['is_active' => false]);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/contractor-marketplace/categories');

        $response->assertOk();

        $slugs = collect($response->json('data'))
            ->flatMap(static fn (array $category): array => array_merge(
                [$category['slug']],
                array_column($category['children'] ?? [], 'slug')
            ))
            ->values()
            ->all();

        $this->assertNotContains('monolith', $slugs);
    }

    public function test_categories_endpoint_requires_permission(): void
    {
        $this->denyPermission('contractor_marketplace.categories.view');
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/contractor-marketplace/categories');

        $response->assertForbidden();
        $response->assertJsonPath('success', false);
    }

    private function allowPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function denyPermission(string $deniedPermission): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($deniedPermission): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn ($user, string $permission, ?array $context = null): bool => $permission !== $deniedPermission
            );
        });
    }
}
