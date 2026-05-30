<?php

declare(strict_types=1);

namespace Tests\Feature\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceHiringOffer;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceWorkCategory;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ContractorType;
use App\Enums\OrganizationCapability;
use App\Enums\ProjectOrganizationRole;
use App\Models\Activity\ActivityEvent;
use App\Models\Contractor;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class MarketplaceHiringOfferControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_sent_offer_for_visible_contractor_from_closed_network(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);

        $response = $this->withHeaders($this->adminHeaders($context))
            ->postJson('/api/v1/admin/contractor-marketplace/offers', $this->offerPayload($project, $profile, $category));

        $response->assertCreated();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.status', 'sent');
        $response->assertJsonPath('data.project.id', $project->id);
        $response->assertJsonPath('data.contractor_profile.id', $profile->id);
        $response->assertJsonPath('data.work_packages.0.category.id', $category->id);

        $this->assertDatabaseHas('marketplace_hiring_offers', [
            'project_id' => $project->id,
            'hiring_organization_id' => $context->organization->id,
            'contractor_organization_id' => $profile->organization_id,
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $context->organization->id,
            'actor_user_id' => $context->user->id,
            'project_id' => $project->id,
            'module' => 'contractor-marketplace',
            'event_type' => 'contractor_marketplace.offer.sent',
            'subject_type' => 'marketplace_hiring_offer',
        ]);
    }

    public function test_admin_cannot_create_offer_for_forbidden_project(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $otherOrganization = Organization::factory()->verified()->create();
        $project = Project::factory()->create(['organization_id' => $otherOrganization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);

        $response = $this->withHeaders($this->adminHeaders($context))
            ->postJson('/api/v1/admin/contractor-marketplace/offers', $this->offerPayload($project, $profile, $category));

        $response->assertForbidden();
        $this->assertDatabaseCount('marketplace_hiring_offers', 0);
    }

    public function test_admin_cannot_create_offer_for_contractor_outside_closed_network(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createProfile();

        $response = $this->withHeaders($this->adminHeaders($context))
            ->postJson('/api/v1/admin/contractor-marketplace/offers', $this->offerPayload($project, $profile, $category));

        $response->assertForbidden();
        $this->assertDatabaseCount('marketplace_hiring_offers', 0);
    }

    public function test_contractor_accepts_offer_and_is_attached_to_project(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);

        $response = $this->withHeaders($this->landingHeaders($contractorUser, $contractorOrganization))
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/accept");

        $response->assertOk();
        $response->assertJsonPath('data.status', 'accepted');
        $this->assertDatabaseHas('project_organization', [
            'project_id' => $project->id,
            'organization_id' => $profile->organization_id,
            'role_new' => ProjectOrganizationRole::CONTRACTOR->value,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('contractors', [
            'organization_id' => $context->organization->id,
            'source_organization_id' => $profile->organization_id,
        ]);
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $profile->organization_id,
            'actor_user_id' => $contractorUser->id,
            'project_id' => $project->id,
            'module' => 'contractor-marketplace',
            'event_type' => 'contractor_marketplace.offer.accepted',
            'subject_id' => $offer->id,
        ]);
    }

    public function test_contractor_view_records_activity_once(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);
        $headers = $this->landingHeaders($contractorUser, $contractorOrganization);

        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/view");
        $second = $this->withHeaders($headers)
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/view");

        $first->assertOk();
        $first->assertJsonPath('data.status', 'viewed');
        $second->assertOk();
        $this->assertSame(1, ActivityEvent::query()
            ->where('event_type', 'contractor_marketplace.offer.viewed')
            ->where('subject_id', $offer->id)
            ->count());
    }

    public function test_accept_offer_is_idempotent_for_same_contractor(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);
        $headers = $this->landingHeaders($contractorUser, $contractorOrganization);

        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/accept");
        $second = $this->withHeaders($headers)
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/accept");

        $first->assertOk();
        $second->assertOk();
        $second->assertJsonPath('data.status', 'accepted');
        $this->assertSame(1, (int) DB::table('project_organization')
            ->where('project_id', $project->id)
            ->where('organization_id', $profile->organization_id)
            ->where('is_active', true)
            ->count());
        $this->assertSame(1, ActivityEvent::query()
            ->where('event_type', 'contractor_marketplace.offer.accepted')
            ->where('subject_id', $offer->id)
            ->count());
    }

    public function test_decline_stores_reason_and_does_not_attach_project_access(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);

        $response = $this->withHeaders($this->landingHeaders($contractorUser, $contractorOrganization))
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/decline", [
                'reason' => 'No team capacity this month',
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'declined');
        $this->assertDatabaseHas('marketplace_hiring_offers', [
            'id' => $offer->id,
            'status' => 'declined',
            'decline_reason' => 'No team capacity this month',
        ]);
        $this->assertDatabaseMissing('project_organization', [
            'project_id' => $project->id,
            'organization_id' => $profile->organization_id,
            'is_active' => true,
        ]);
        $event = ActivityEvent::query()
            ->where('event_type', 'contractor_marketplace.offer.declined')
            ->where('subject_id', $offer->id)
            ->firstOrFail();
        $this->assertSame($profile->organization_id, $event->organization_id);
        $this->assertSame('No team capacity this month', $event->context['decline_reason']);
    }

    public function test_cancelled_offer_cannot_be_accepted(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);

        $cancel = $this->withHeaders($this->adminHeaders($context))
            ->postJson("/api/v1/admin/contractor-marketplace/offers/{$offer->id}/cancel");
        $accept = $this->withHeaders($this->landingHeaders($contractorUser, $contractorOrganization))
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/accept");

        $cancel->assertOk();
        $cancel->assertJsonPath('data.status', 'cancelled');
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $context->organization->id,
            'actor_user_id' => $context->user->id,
            'project_id' => $project->id,
            'module' => 'contractor-marketplace',
            'event_type' => 'contractor_marketplace.offer.cancelled',
            'subject_id' => $offer->id,
        ]);
        $accept->assertStatus(409);
        $this->assertDatabaseMissing('project_organization', [
            'project_id' => $project->id,
            'organization_id' => $profile->organization_id,
            'is_active' => true,
        ]);
    }

    public function test_accepted_offer_cannot_be_cancelled(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);

        $accept = $this->withHeaders($this->landingHeaders($contractorUser, $contractorOrganization))
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/accept");
        $cancel = $this->withHeaders($this->adminHeaders($context))
            ->postJson("/api/v1/admin/contractor-marketplace/offers/{$offer->id}/cancel");

        $accept->assertOk();
        $cancel->assertStatus(409);
        $this->assertDatabaseHas('marketplace_hiring_offers', [
            'id' => $offer->id,
            'status' => 'accepted',
        ]);
    }

    public function test_hiring_organization_reviews_accepted_offer_and_updates_category_rating(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);
        [$contractorOrganization, $contractorUser] = $this->createLandingContext($profile->organization);

        $accept = $this->withHeaders($this->landingHeaders($contractorUser, $contractorOrganization))
            ->postJson("/api/v1/landing/contractor-marketplace/offers/{$offer->id}/accept");
        $review = $this->withHeaders($this->adminHeaders($context))
            ->postJson("/api/v1/admin/contractor-marketplace/offers/{$offer->id}/review", [
                'reviews' => [
                    [
                        'category_id' => $category->id,
                        'quality_score' => 5,
                        'deadline_score' => 4,
                        'communication_score' => 5,
                        'safety_score' => 4,
                        'financial_discipline_score' => 5,
                        'comment' => 'Р‘СЂРёРіР°РґР° РѕС‚СЂР°Р±РѕС‚Р°Р»Р° СЃС‚Р°Р±РёР»СЊРЅРѕ, РґРµС„РµРєС‚С‹ Р·Р°РєСЂС‹РІР°Р»Рё Р±С‹СЃС‚СЂРѕ.',
                    ],
                ],
            ]);

        $accept->assertOk();
        $review->assertOk();
        $review->assertJsonPath('data.reviews.0.category.id', $category->id);
        $review->assertJsonPath('data.contractor_profile.ratings.0.reviews_count', 1);
        $review->assertJsonPath('data.contractor_profile.ratings.0.completed_offers_count', 1);

        $this->assertDatabaseHas('marketplace_hiring_offer_reviews', [
            'offer_id' => $offer->id,
            'category_id' => $category->id,
            'reviewer_organization_id' => $context->organization->id,
            'contractor_profile_id' => $profile->id,
        ]);
        $this->assertDatabaseHas('marketplace_contractor_ratings', [
            'profile_id' => $profile->id,
            'category_id' => $category->id,
            'reviews_count' => 1,
            'completed_offers_count' => 1,
        ]);
        $this->assertDatabaseHas('marketplace_contractor_categories', [
            'profile_id' => $profile->id,
            'category_id' => $category->id,
            'ratings_count' => 1,
            'completed_projects_count' => 1,
        ]);
        $this->assertDatabaseHas('activity_events', [
            'organization_id' => $context->organization->id,
            'actor_user_id' => $context->user->id,
            'project_id' => $project->id,
            'module' => 'contractor-marketplace',
            'event_type' => 'contractor_marketplace.offer.reviewed',
            'subject_id' => $offer->id,
        ]);
    }

    public function test_sent_offer_cannot_be_reviewed_before_acceptance(): void
    {
        $this->allowPermission();
        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        [$profile, $category] = $this->createConnectedProfile($context->organization);
        $offer = $this->createOfferThroughApi($context, $project, $profile, $category);

        $response = $this->withHeaders($this->adminHeaders($context))
            ->postJson("/api/v1/admin/contractor-marketplace/offers/{$offer->id}/review", [
                'reviews' => [
                    [
                        'category_id' => $category->id,
                        'quality_score' => 5,
                        'deadline_score' => 5,
                        'communication_score' => 5,
                    ],
                ],
            ]);

        $response->assertStatus(409);
        $this->assertDatabaseMissing('marketplace_hiring_offer_reviews', [
            'offer_id' => $offer->id,
        ]);
        $this->assertDatabaseMissing('marketplace_contractor_ratings', [
            'profile_id' => $profile->id,
            'category_id' => $category->id,
        ]);
    }

    private function allowPermission(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    /**
     * @return array{0: MarketplaceContractorProfile, 1: MarketplaceWorkCategory}
     */
    private function createConnectedProfile(Organization $hiringOrganization): array
    {
        [$profile, $category] = $this->createProfile();

        Contractor::query()->create([
            'organization_id' => $hiringOrganization->id,
            'source_organization_id' => $profile->organization_id,
            'name' => $profile->organization->name,
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
            'connected_at' => now(),
        ]);

        return [$profile, $category];
    }

    /**
     * @return array{0: MarketplaceContractorProfile, 1: MarketplaceWorkCategory}
     */
    private function createProfile(): array
    {
        $organization = Organization::factory()->verified()->create([
            'capabilities' => [OrganizationCapability::SUBCONTRACTING->value],
            'primary_business_type' => OrganizationCapability::SUBCONTRACTING->value,
        ]);
        $category = MarketplaceWorkCategory::query()->where('slug', 'monolith')->firstOrFail();
        $profile = MarketplaceContractorProfile::query()->create([
            'organization_id' => $organization->id,
            'status' => 'active',
            'display_name' => 'Monolith Crew',
            'short_description' => 'Monolith works team',
            'description' => 'Production-ready team for monolith works.',
            'team_size_min' => 4,
            'team_size_max' => 20,
            'base_city' => 'Kazan',
            'availability_status' => 'available',
            'verification_level' => 'basic',
            'is_visible_in_marketplace' => true,
            'published_at' => now(),
            'metadata' => [],
        ]);
        $profile->categories()->create([
            'category_id' => $category->id,
            'is_primary' => true,
            'experience_years' => 5,
            'team_capacity' => 20,
            'min_project_budget' => 100000,
            'max_project_budget' => 5000000,
        ]);

        return [$profile->fresh('organization'), $category];
    }

    private function offerPayload(Project $project, MarketplaceContractorProfile $profile, MarketplaceWorkCategory $category): array
    {
        return [
            'project_id' => $project->id,
            'contractor_profile_id' => $profile->id,
            'role' => ProjectOrganizationRole::CONTRACTOR->value,
            'title' => 'Monolith package',
            'message' => 'Please join the project team.',
            'starts_at' => now()->addWeek()->toISOString(),
            'ends_at' => now()->addWeeks(6)->toISOString(),
            'budget_min' => 200000,
            'budget_max' => 900000,
            'currency' => 'RUB',
            'work_packages' => [
                [
                    'category_id' => $category->id,
                    'title' => 'Monolith works',
                    'description' => 'Concrete frame works.',
                    'quantity' => 120,
                    'unit' => 'm3',
                    'budget_min' => 200000,
                    'budget_max' => 900000,
                    'starts_at' => now()->addWeek()->toISOString(),
                    'ends_at' => now()->addWeeks(6)->toISOString(),
                ],
            ],
        ];
    }

    private function createOfferThroughApi(
        AdminApiTestContext $context,
        Project $project,
        MarketplaceContractorProfile $profile,
        MarketplaceWorkCategory $category
    ): MarketplaceHiringOffer {
        $response = $this->withHeaders($this->adminHeaders($context))
            ->postJson('/api/v1/admin/contractor-marketplace/offers', $this->offerPayload($project, $profile, $category));

        $response->assertCreated();

        return MarketplaceHiringOffer::query()->findOrFail((int) $response->json('data.id'));
    }

    /**
     * @return array{0: Organization, 1: User}
     */
    private function createLandingContext(Organization $organization): array
    {
        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

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

        return [$organization, $user];
    }

    private function landingHeaders(User $user, Organization $organization): array
    {
        Auth::forgetGuards();
        $this->actingAs($user, 'api_landing');

        return [
            'Authorization' => 'Bearer ' . JWTAuth::claims([
                'organization_id' => $organization->id,
            ])->fromUser($user),
            'Accept' => 'application/json',
        ];
    }

    private function adminHeaders(AdminApiTestContext $context): array
    {
        Auth::forgetGuards();
        $this->actingAs($context->user, 'api_admin');

        return $context->authHeaders();
    }
}
