<?php

declare(strict_types=1);

namespace Tests\Feature\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Activity\ActivityEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class MarketplaceProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_endpoint_returns_current_organization_draft(): void
    {
        $this->allowPermission();
        [$organization, $user] = $this->createLandingContext();

        $response = $this->withHeaders($this->landingHeaders($user, $organization))
            ->getJson('/api/v1/landing/contractor-marketplace/profile');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.organization_id', $organization->id);
        $response->assertJsonPath('data.status', 'draft');
        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $organization->id,
            'status' => 'draft',
        ]);
    }

    public function test_landing_categories_endpoint_returns_active_tree(): void
    {
        $this->allowPermission();
        [$organization, $user] = $this->createLandingContext();

        $response = $this->withHeaders($this->landingHeaders($user, $organization))
            ->getJson('/api/v1/landing/contractor-marketplace/categories');

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
    }

    public function test_profile_update_stores_categories_and_regions_for_current_organization(): void
    {
        $this->allowPermission();
        [$organization, $user] = $this->createLandingContext();
        $category = MarketplaceWorkCategory::query()->where('slug', 'monolith')->firstOrFail();

        $response = $this->withHeaders($this->landingHeaders($user, $organization))
            ->putJson('/api/v1/landing/contractor-marketplace/profile', [
                'display_name' => 'Монолит Про',
                'short_description' => 'Монолитные работы и бетон',
                'description' => 'Команда для монолитных работ на промышленных объектах.',
                'team_size_min' => 5,
                'team_size_max' => 25,
                'years_on_market' => 7,
                'base_city' => 'Казань',
                'service_radius_km' => 300,
                'availability_status' => 'available',
                'categories' => [
                    [
                        'category_id' => $category->id,
                        'is_primary' => true,
                        'experience_years' => 6,
                        'team_capacity' => 25,
                        'min_project_budget' => 1000000,
                        'max_project_budget' => 15000000,
                    ],
                ],
                'regions' => [
                    ['country' => 'Россия', 'region' => 'Татарстан', 'city' => 'Казань', 'is_primary' => true],
                ],
                'portfolio_items' => [
                    [
                        'category_id' => $category->id,
                        'title' => 'Монолит корпуса 1',
                        'description' => 'Выполнен каркас жилого корпуса.',
                        'city' => 'Казань',
                        'completed_at' => now()->subMonth()->toDateString(),
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.display_name', 'Монолит Про');
        $response->assertJsonPath('data.categories.0.category.slug', 'monolith');
        $response->assertJsonPath('data.regions.0.city', 'Казань');
        $response->assertJsonPath('data.portfolio_items.0.title', 'Монолит корпуса 1');
    }

    public function test_profile_document_upload_and_delete_uses_organization_s3_path(): void
    {
        Storage::fake('s3');
        $this->allowPermission();
        [$organization, $user] = $this->createLandingContext();
        $headers = $this->landingHeaders($user, $organization);

        $upload = $this->withHeaders($headers)
            ->post('/api/v1/landing/contractor-marketplace/profile/documents', [
                'type' => 'license',
                'title' => 'Лицензия СРО',
                'document' => UploadedFile::fake()->createWithContent('license.pdf', "%PDF-1.4\n" . str_repeat('a', 1024)),
            ]);

        $upload->assertCreated();
        $documentId = (int) $upload->json('data.documents.0.id');
        $document = MarketplaceContractorProfile::query()
            ->where('organization_id', $organization->id)
            ->firstOrFail()
            ->documents()
            ->firstOrFail();

        $this->assertStringStartsWith("org-{$organization->id}/contractor-marketplace/", $document->file_path);
        Storage::disk('s3')->assertExists($document->file_path);

        $delete = $this->withHeaders($headers)
            ->deleteJson("/api/v1/landing/contractor-marketplace/profile/documents/{$documentId}");

        $delete->assertOk();
        $delete->assertJsonPath('data.documents', []);
        $this->assertDatabaseMissing('marketplace_contractor_documents', [
            'id' => $documentId,
        ]);
    }

    public function test_publish_requires_complete_profile_and_category(): void
    {
        $this->allowPermission();
        [$organization, $user] = $this->createLandingContext();

        $response = $this->withHeaders($this->landingHeaders($user, $organization))
            ->postJson('/api/v1/landing/contractor-marketplace/profile/publish');

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
        $this->assertStringNotContainsString('fallback', (string) $response->json('message'));
    }

    public function test_publish_and_pause_profile_lifecycle(): void
    {
        $this->allowPermission();
        [$organization, $user] = $this->createLandingContext();
        $category = MarketplaceWorkCategory::query()->where('slug', 'electrical')->firstOrFail();
        $profile = MarketplaceContractorProfile::query()->create([
            'organization_id' => $organization->id,
            'status' => 'draft',
            'display_name' => 'Электро Монтаж',
            'short_description' => 'Электромонтажные работы',
            'description' => 'Бригада электромонтажа для коммерческих объектов.',
            'team_size_min' => 4,
            'team_size_max' => 12,
            'years_on_market' => 5,
            'base_city' => 'Москва',
            'availability_status' => 'available',
            'is_visible_in_marketplace' => false,
            'verification_level' => 'basic',
            'metadata' => [],
        ]);
        $profile->categories()->create([
            'category_id' => $category->id,
            'is_primary' => true,
            'experience_years' => 5,
            'team_capacity' => 12,
        ]);

        $publish = $this->withHeaders($this->landingHeaders($user, $organization))
            ->postJson('/api/v1/landing/contractor-marketplace/profile/publish');

        $publish->assertOk();
        $publish->assertJsonPath('data.status', 'active');
        $publish->assertJsonPath('data.is_visible_in_marketplace', true);
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'module' => 'contractor-marketplace',
            'event_type' => 'contractor_marketplace.profile.published',
            'subject_type' => 'marketplace_contractor_profile',
            'subject_id' => $profile->id,
        ]);

        $pause = $this->withHeaders($this->landingHeaders($user, $organization))
            ->postJson('/api/v1/landing/contractor-marketplace/profile/pause');

        $pause->assertOk();
        $pause->assertJsonPath('data.status', 'paused');
        $pause->assertJsonPath('data.is_visible_in_marketplace', false);
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $organization->id,
            'actor_user_id' => $user->id,
            'module' => 'contractor-marketplace',
            'event_type' => 'contractor_marketplace.profile.paused',
            'subject_type' => 'marketplace_contractor_profile',
            'subject_id' => $profile->id,
        ]);
        $this->assertSame(2, ActivityEvent::query()
            ->where('subject_type', 'marketplace_contractor_profile')
            ->where('subject_id', $profile->id)
            ->count());
    }

    public function test_jwt_context_prevents_cross_organization_profile_update(): void
    {
        $this->allowPermission();
        [$firstOrganization, $user] = $this->createLandingContext();
        $secondOrganization = Organization::factory()->verified()->create();
        $this->attachUserToOrganization($user, $secondOrganization);

        MarketplaceContractorProfile::query()->create([
            'organization_id' => $firstOrganization->id,
            'status' => 'draft',
            'display_name' => 'First Profile',
            'availability_status' => 'available',
            'verification_level' => 'none',
            'is_visible_in_marketplace' => false,
            'metadata' => [],
        ]);

        $response = $this->withHeaders($this->landingHeaders($user, $secondOrganization))
            ->putJson('/api/v1/landing/contractor-marketplace/profile', [
                'display_name' => 'Second Profile',
                'base_city' => 'Самара',
                'availability_status' => 'available',
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $firstOrganization->id,
            'display_name' => 'First Profile',
        ]);
        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $secondOrganization->id,
            'display_name' => 'Second Profile',
        ]);
    }

    private function allowPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createLandingContext(): array
    {
        $organization = Organization::factory()->verified()->create();
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $this->attachUserToOrganization($user, $organization);

        return [$organization, $user];
    }

    private function attachUserToOrganization(User $user, Organization $organization): void
    {
        $organization->users()->syncWithoutDetaching([
            $user->id => [
                'is_owner' => true,
                'is_active' => true,
                'settings' => null,
            ],
        ]);

        UserRoleAssignment::assignRole(
            user: $user,
            roleSlug: 'organization_admin',
            context: AuthorizationContext::getOrganizationContext($organization->id)
        );
    }

    private function landingHeaders(User $user, Organization $organization): array
    {
        return [
            'Authorization' => 'Bearer ' . JWTAuth::claims([
                'organization_id' => $organization->id,
            ])->fromUser($user),
            'Accept' => 'application/json',
        ];
    }
}
