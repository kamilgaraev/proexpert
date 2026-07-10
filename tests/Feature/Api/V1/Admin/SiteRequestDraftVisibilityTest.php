<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestGroup;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class SiteRequestDraftVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_only_contains_own_drafts_and_visible_non_drafts_with_correct_total(): void
    {
        $context = AdminApiTestContext::create();
        [$otherUser, $otherHeaders] = $this->createActor($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $ownDraft = $this->createRequest($context->organization, $project, $otherUser, SiteRequestStatusEnum::DRAFT);
        $foreignDraft = $this->createRequest($context->organization, $project, $context->user, SiteRequestStatusEnum::DRAFT);
        $foreignPending = $this->createRequest($context->organization, $project, $context->user, SiteRequestStatusEnum::PENDING);
        $this->allowAccess();

        $response = $this->withHeaders($otherHeaders)
            ->getJson('/api/v1/admin/site-requests?per_page=20');

        $response->assertOk()->assertJsonPath('data.meta.total', 2);
        $ids = collect($response->json('data.data'))->pluck('id')->all();

        $this->assertContains($ownDraft->id, $ids);
        $this->assertContains($foreignPending->id, $ids);
        $this->assertNotContains($foreignDraft->id, $ids);
    }

    public function test_list_cannot_expose_foreign_draft_through_status_and_owner_filters(): void
    {
        $context = AdminApiTestContext::create();
        [$otherUser, $otherHeaders] = $this->createActor($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignDraft = $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::DRAFT
        );
        $this->createRequest($context->organization, $project, $otherUser, SiteRequestStatusEnum::DRAFT);
        $this->allowAccess();

        $response = $this->withHeaders($otherHeaders)->getJson(sprintf(
            '/api/v1/admin/site-requests?status=%s&user_id=%d&per_page=20',
            SiteRequestStatusEnum::DRAFT->value,
            $context->user->id
        ));

        $response->assertOk()->assertJsonPath('data.meta.total', 0);
        $this->assertNotContains(
            $foreignDraft->id,
            collect($response->json('data.data'))->pluck('id')->all()
        );
    }

    public function test_procurement_chain_cannot_open_another_users_draft(): void
    {
        $context = AdminApiTestContext::create();
        [, $otherHeaders] = $this->createActor($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignDraft = $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::DRAFT
        );
        $this->allowAccess();

        $this->withHeaders($otherHeaders)
            ->getJson("/api/v1/admin/procurement/chains/site-requests/{$foreignDraft->id}")
            ->assertNotFound();
    }

    public function test_foreign_draft_read_and_mutations_return_not_found_without_mutation(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        [, $otherHeaders] = $this->createActor($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignDraft = $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::DRAFT,
            ['title' => 'Private draft']
        );
        $this->allowAccess();

        $this->withHeaders($otherHeaders)
            ->getJson("/api/v1/admin/site-requests/{$foreignDraft->id}")
            ->assertNotFound();

        $this->withHeaders($otherHeaders)
            ->putJson("/api/v1/admin/site-requests/{$foreignDraft->id}", ['title' => 'Unauthorized change'])
            ->assertNotFound();

        $this->withHeaders($otherHeaders)
            ->deleteJson("/api/v1/admin/site-requests/{$foreignDraft->id}")
            ->assertNotFound();

        $this->withHeaders($otherHeaders)
            ->postJson("/api/v1/admin/site-requests/{$foreignDraft->id}/submit")
            ->assertNotFound();

        $this->assertDatabaseHas('site_requests', [
            'id' => $foreignDraft->id,
            'title' => 'Private draft',
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'deleted_at' => null,
        ]);
        $this->assertDatabaseMissing('site_request_history', [
            'site_request_id' => $foreignDraft->id,
        ]);
    }

    public function test_non_draft_request_cannot_be_deleted(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $pending = $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::PENDING
        );
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->deleteJson("/api/v1/admin/site-requests/{$pending->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('site_requests', [
            'id' => $pending->id,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'deleted_at' => null,
        ]);
    }

    public function test_statistics_and_overdue_exclude_foreign_drafts_and_cache_is_actor_isolated(): void
    {
        $context = AdminApiTestContext::create();
        [$otherUser, $otherHeaders] = $this->createActor($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $commonPending = $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::PENDING,
            ['required_date' => now()->subDay()->toDateString()]
        );
        $contextDraft = $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::DRAFT,
            ['required_date' => now()->subDay()->toDateString()]
        );
        $this->createRequest($context->organization, $project, $context->user, SiteRequestStatusEnum::DRAFT);
        $otherDraft = $this->createRequest(
            $context->organization,
            $project,
            $otherUser,
            SiteRequestStatusEnum::DRAFT,
            ['required_date' => now()->subDay()->toDateString()]
        );
        $this->allowAccess();

        $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/site-requests/dashboard/statistics')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.overdue', 2);

        $this->withHeaders($otherHeaders)
            ->getJson('/api/v1/admin/site-requests/dashboard/statistics')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.overdue', 2);

        $overdueResponse = $this->withHeaders($otherHeaders)
            ->getJson('/api/v1/admin/site-requests/dashboard/overdue')
            ->assertOk()
            ->assertJsonPath('data.count', 2);
        $overdueIds = collect($overdueResponse->json('data.items'))->pluck('id')->all();

        $this->assertContains($commonPending->id, $overdueIds);
        $this->assertContains($otherDraft->id, $overdueIds);
        $this->assertNotContains($contextDraft->id, $overdueIds);
    }

    public function test_draft_group_is_private_while_submitted_group_is_visible(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create();
        [, $otherHeaders] = $this->createActor($context->organization);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $draftGroup = $this->createGroup($context->organization, $project, $context->user, SiteRequestStatusEnum::DRAFT);
        $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::DRAFT,
            ['site_request_group_id' => $draftGroup->id]
        );
        $submittedGroup = $this->createGroup(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::PENDING
        );
        $this->createRequest(
            $context->organization,
            $project,
            $context->user,
            SiteRequestStatusEnum::PENDING,
            ['site_request_group_id' => $submittedGroup->id]
        );
        $this->allowAccess();

        $this->withHeaders($otherHeaders)
            ->getJson("/api/v1/admin/site-requests/groups/{$draftGroup->id}")
            ->assertNotFound();

        $this->withHeaders($otherHeaders)
            ->putJson("/api/v1/admin/site-requests/groups/{$draftGroup->id}", ['title' => 'Unauthorized change'])
            ->assertNotFound();

        $this->withHeaders($otherHeaders)
            ->postJson("/api/v1/admin/site-requests/groups/{$draftGroup->id}/submit")
            ->assertNotFound();

        $this->withHeaders($otherHeaders)
            ->getJson("/api/v1/admin/site-requests/groups/{$submittedGroup->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $submittedGroup->id);

        $this->assertDatabaseHas('site_request_groups', [
            'id' => $draftGroup->id,
            'title' => 'Request group',
            'status' => SiteRequestStatusEnum::DRAFT->value,
        ]);
    }

    private function createActor(Organization $organization): array
    {
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $organization->users()->attach($user->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            user: $user,
            roleSlug: 'web_admin',
            context: AuthorizationContext::getOrganizationContext($organization->id)
        );
        $token = JWTAuth::claims(['organization_id' => $organization->id])->fromUser($user);

        return [$user, [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ]];
    }

    private function createRequest(
        Organization $organization,
        Project $project,
        User $user,
        SiteRequestStatusEnum $status,
        array $attributes = []
    ): SiteRequest {
        return SiteRequest::query()->create(array_merge([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => 'Site request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => $status->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete',
            'material_quantity' => 1,
            'material_unit' => 'm3',
        ], $attributes));
    }

    private function createGroup(
        Organization $organization,
        Project $project,
        User $user,
        SiteRequestStatusEnum $status
    ): SiteRequestGroup {
        return SiteRequestGroup::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'title' => 'Request group',
            'status' => $status->value,
        ]);
    }

    private function allowAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturn(true);
        });

        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['web_admin']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }
}
