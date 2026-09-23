<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequestGroup;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\UserProjectAccessMode;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileDashboardService;
use App\Services\Storage\FileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

final class SiteRequestsMobileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_create_update_and_admin_readback_stay_in_sync(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $createResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/site-requests', [
                'project_id' => $project->id,
                'title' => 'Mobile concrete request',
                'description' => 'Concrete for slab section A',
                'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
                'priority' => SiteRequestPriorityEnum::HIGH->value,
                'required_date' => now()->addDays(2)->toDateString(),
                'material_name' => 'Concrete M350',
                'material_quantity' => 6,
                'material_unit' => 'm3',
            ]);

        $createResponse->assertCreated()
            ->assertJsonPath('data.title', 'Mobile concrete request')
            ->assertJsonPath('data.status', SiteRequestStatusEnum::DRAFT->value);

        $requestId = (int) $createResponse->json('data.id');

        $this->assertDatabaseHas('site_requests', [
            'id' => $requestId,
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Mobile concrete request',
            'material_quantity' => 6,
            'material_unit' => 'm3',
        ]);

        $this->flushHeaders();
        $adminShowResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests/{$requestId}");

        $adminShowResponse->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.title', 'Mobile concrete request')
            ->assertJsonPath('data.material_quantity', static fn ($value): bool => (float) $value === 6.0);

        $adminUpdateResponse = $this->withHeaders($context->authHeaders())
            ->putJson("/api/v1/admin/site-requests/{$requestId}", [
                'title' => 'Admin clarified concrete request',
                'description' => 'Admin updated delivery context',
                'priority' => SiteRequestPriorityEnum::URGENT->value,
                'material_quantity' => 8,
                'material_unit' => 'm3',
            ]);

        $adminUpdateResponse->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.title', 'Admin clarified concrete request')
            ->assertJsonPath('data.priority', SiteRequestPriorityEnum::URGENT->value);

        $this->flushHeaders();
        $mobileRefreshResponse = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/site-requests/{$requestId}");

        $mobileRefreshResponse->assertOk()
            ->assertJsonPath('data.id', $requestId)
            ->assertJsonPath('data.title', 'Admin clarified concrete request')
            ->assertJsonPath('data.priority', SiteRequestPriorityEnum::URGENT->value)
            ->assertJsonPath('data.material_quantity', static fn ($value): bool => (float) $value === 8.0);

        $adminListResponse = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/site-requests?project_id={$project->id}&search=clarified");

        $adminListResponse->assertOk();
        $this->assertContains($requestId, collect($adminListResponse->json('data.data'))->pluck('id')->all());
    }

    public function test_mobile_detail_uses_snake_case_procurement_contract_only(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $siteRequest = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Mobile concrete request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete M300',
            'material_quantity' => 12,
            'material_unit' => 'm3',
        ]);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/site-requests/{$siteRequest->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $siteRequest->id)
            ->assertJsonPath('data.purchase_requests', [])
            ->assertJsonPath('data.purchase_orders', []);

        $payload = $response->json('data');

        $this->assertArrayNotHasKey('purchaseRequests', $payload);
        $this->assertArrayNotHasKey('purchaseOrders', $payload);
        $this->assertArrayHasKey('available_transitions', $payload);
    }

    public function test_mobile_reviewer_cannot_open_another_users_draft_by_id(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $reviewer = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($reviewer->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            user: $reviewer,
            roleSlug: 'foreman',
            context: AuthorizationContext::getOrganizationContext($context->organization->id)
        );
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $draft = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Private mobile draft',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete',
            'material_quantity' => 1,
            'material_unit' => 'm3',
        ]);
        $token = JWTAuth::claims(['organization_id' => $context->organization->id])->fromUser($reviewer);
        $this->allowAccess();

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/mobile/site-requests/{$draft->id}")
            ->assertNotFound();
    }

    public function test_mobile_group_context_does_not_leak_foreign_draft_sibling(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $reviewer = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($reviewer->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        UserRoleAssignment::assignRole(
            user: $reviewer,
            roleSlug: 'foreman',
            context: AuthorizationContext::getOrganizationContext($context->organization->id)
        );
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $group = SiteRequestGroup::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => 'Mixed visibility group',
            'status' => SiteRequestStatusEnum::PENDING->value,
        ]);
        $pending = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'site_request_group_id' => $group->id,
            'title' => 'Visible pending item',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete',
            'material_quantity' => 1,
            'material_unit' => 'm3',
        ]);
        $foreignDraft = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'site_request_group_id' => $group->id,
            'title' => 'Private draft sibling',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::DRAFT->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Private material',
            'material_quantity' => 2,
            'material_unit' => 'pcs',
        ]);
        $token = JWTAuth::claims(['organization_id' => $context->organization->id])->fromUser($reviewer);
        $this->allowAccess();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson("/api/v1/mobile/site-requests/{$pending->id}");

        $response->assertOk()
            ->assertJsonPath('data.group_context.request_count', 1)
            ->assertJsonPath('data.group_context.items.0.id', $pending->id);
        $this->assertNotContains(
            $foreignDraft->id,
            collect($response->json('data.group_context.items'))->pluck('id')->all()
        );
    }

    public function test_mobile_site_request_list_uses_admin_paginated_envelope_without_project_id(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $firstProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $secondProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $firstRequest = $this->createSiteRequest($context, $firstProject, SiteRequestStatusEnum::DRAFT, 'First object request');
        $secondRequest = $this->createSiteRequest($context, $secondProject, SiteRequestStatusEnum::PENDING, 'Second object request');

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests?per_page=50');

        $response->assertOk()
            ->assertJsonPath('success', true);
        $this->assertIsList($response->json('data'));
        $this->assertArrayHasKey('meta', $response->json());
        $this->assertSame(
            ['current_page', 'per_page', 'total', 'last_page'],
            array_keys($response->json('meta'))
        );
        $this->assertArrayNotHasKey('current_page', $response->json('data'));
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($firstRequest->id, $ids);
        $this->assertContains($secondRequest->id, $ids);
        $this->assertGreaterThanOrEqual(2, (int) $response->json('meta.total'));

        $projectFiltered = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests?project_id='.$firstProject->id);

        $projectFiltered->assertOk();
        $filteredIds = collect($projectFiltered->json('data'))->pluck('id')->all();
        $this->assertContains($firstRequest->id, $filteredIds);
        $this->assertNotContains($secondRequest->id, $filteredIds);
    }

    public function test_mobile_approvals_without_project_id_match_dashboard_pending_and_in_review(): void
    {
        Event::fake();

        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $author = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($author->id, [
            'is_owner' => false,
            'is_active' => true,
            'settings' => null,
        ]);
        $firstProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $secondProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $this->createSiteRequest($context, $firstProject, SiteRequestStatusEnum::DRAFT, 'Own draft');
        $inReview = $this->createSiteRequest($context, $firstProject, SiteRequestStatusEnum::IN_REVIEW, 'Needs review');
        $pending = SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $secondProject->id,
            'user_id' => $author->id,
            'title' => 'Pending on another object',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => SiteRequestStatusEnum::PENDING->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Bricks',
            'material_quantity' => 20,
            'material_unit' => 'pcs',
        ]);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests?scope=approvals&per_page=50');

        $response->assertOk();
        $this->assertIsList($response->json('data'));
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertContains($inReview->id, $ids);
        $this->assertContains($pending->id, $ids);
        $this->assertSame(2, (int) $response->json('meta.total'));
        $this->assertSame(2, count($ids));

        $dashboard = app(MobileDashboardService::class)->build($context->user);
        $approvalsWidget = collect($dashboard['widgets'])->firstWhere('slug', 'site_request_approvals');
        $this->assertNotNull($approvalsWidget);
        $this->assertSame(1, $approvalsWidget['primary_metric']['value']);
        $this->assertSame(1, $approvalsWidget['secondary_metric']['value']);
        $this->assertSame(
            (int) $response->json('meta.total'),
            $approvalsWidget['primary_metric']['value'] + $approvalsWidget['secondary_metric']['value']
        );

        $projectFiltered = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests?scope=approvals&project_id='.$firstProject->id);

        $projectFiltered->assertOk();
        $filteredIds = collect($projectFiltered->json('data'))->pluck('id')->all();
        $this->assertContains($inReview->id, $filteredIds);
        $this->assertNotContains($pending->id, $filteredIds);
    }

    public function test_mobile_site_request_list_rejects_empty_project_access(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => UserProjectAccessMode::ASSIGNED_PROJECTS->value,
        ]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $this->createSiteRequest($context, $project, SiteRequestStatusEnum::PENDING, 'Hidden request');

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', trans_message('site_requests::mobile.no_accessible_projects'))
            ->assertJsonPath('data', null);
    }

    public function test_mobile_site_request_filters_run_on_server_for_urgent_assigned_and_required_dates(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();

        $urgent = $this->createSiteRequest($context, $project, SiteRequestStatusEnum::PENDING, 'Urgent overdue request');
        $urgent->update([
            'priority' => SiteRequestPriorityEnum::URGENT->value,
            'assigned_to' => $context->user->id,
            'required_date' => now()->subDay()->toDateString(),
        ]);
        $regular = $this->createSiteRequest($context, $project, SiteRequestStatusEnum::PENDING, 'Regular request');
        $regular->update(['required_date' => now()->addDay()->toDateString()]);

        $response = $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/site-requests?scope=all&urgent=true&assigned_user_id='.$context->user->id.'&request_type='.SiteRequestTypeEnum::MATERIAL_REQUEST->value.'&overdue=true&required_from='.now()->subDays(2)->toDateString().'&required_to='.now()->toDateString());

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$urgent->id], $ids);
    }

    public function test_mobile_site_request_creation_is_idempotent_for_offline_retries(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $payload = [
            'project_id' => $project->id,
            'title' => 'Retry-safe material request',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'material_name' => 'Concrete M300',
            'material_quantity' => 3,
            'material_unit' => 'm3',
        ];
        $headers = array_merge($context->mobileAuthHeaders(), ['Idempotency-Key' => 'mobile-retry-123']);

        $first = $this->withHeaders($headers)->postJson('/api/v1/mobile/site-requests', $payload);
        $second = $this->withHeaders($headers)->postJson('/api/v1/mobile/site-requests', $payload);

        $first->assertCreated();
        $second->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertDatabaseCount('site_requests', 1);
        Event::assertDispatchedTimes(\App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated::class, 1);

        $this->withHeaders($headers)->postJson('/api/v1/mobile/site-requests', [...$payload, 'title' => 'Different payload'])
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('site_requests::mobile.idempotency_conflict'));
        $this->assertDatabaseCount('site_requests', 1);
    }

    public function test_mobile_batch_creation_is_idempotent_for_offline_retries(): void
    {
        Event::fake();
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $payload = [
            'project_id' => $project->id,
            'title' => 'Retry-safe material batch',
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'materials' => [
                ['name' => 'Concrete M300', 'quantity' => 3, 'unit' => 'm3'],
                ['name' => 'Rebar', 'quantity' => 10, 'unit' => 'kg'],
            ],
        ];
        $headers = array_merge($context->mobileAuthHeaders(), ['Idempotency-Key' => 'mobile-batch-retry-123']);

        $first = $this->withHeaders($headers)->postJson('/api/v1/mobile/site-requests', $payload);
        $second = $this->withHeaders($headers)->postJson('/api/v1/mobile/site-requests', $payload);

        $first->assertCreated();
        $second->assertCreated()
            ->assertJsonPath('data.group_id', $first->json('data.group_id'))
            ->assertJsonPath('data.request_ids', $first->json('data.request_ids'));
        $this->assertDatabaseCount('site_request_groups', 1);
        $this->assertDatabaseCount('site_requests', 2);
        Event::assertDispatchedTimes(\App\BusinessModules\Features\SiteRequests\Events\SiteRequestCreated::class, 2);

        $changedPayload = $payload;
        $changedPayload['materials'][0]['quantity'] = 4;
        $this->withHeaders($headers)->postJson('/api/v1/mobile/site-requests', $changedPayload)
            ->assertStatus(422)
            ->assertJsonPath('message', trans_message('site_requests::mobile.idempotency_conflict'));
        $this->assertDatabaseCount('site_request_groups', 1);
        $this->assertDatabaseCount('site_requests', 2);
    }

    public function test_mobile_assignee_list_and_assignment_require_an_active_project_member(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $candidate = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($candidate->id, ['is_owner' => false, 'is_active' => true]);
        $project->users()->attach($candidate->id, ['role' => 'member', 'is_active' => true]);
        $this->allowAccess();
        $siteRequest = $this->createSiteRequest($context, $project, SiteRequestStatusEnum::PENDING, 'Assign me');

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/site-requests/{$siteRequest->id}/assignees")
            ->assertOk()
            ->assertJsonFragment(['id' => $candidate->id, 'name' => $candidate->name]);

        $this->withHeaders($context->mobileAuthHeaders())
            ->putJson("/api/v1/mobile/site-requests/{$siteRequest->id}/assignee", ['assigned_user_id' => $candidate->id])
            ->assertOk()
            ->assertJsonPath('data.assigned_user.id', $candidate->id);

        $outsideCandidate = User::factory()->create(['current_organization_id' => $context->organization->id]);
        $context->organization->users()->attach($outsideCandidate->id, ['is_owner' => false, 'is_active' => true]);
        $this->withHeaders($context->mobileAuthHeaders())
            ->putJson("/api/v1/mobile/site-requests/{$siteRequest->id}/assignee", ['assigned_user_id' => $outsideCandidate->id])
            ->assertStatus(422);
    }

    public function test_mobile_site_request_file_upload_list_and_delete_use_private_storage(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAccess();
        $siteRequest = $this->createSiteRequest($context, $project, SiteRequestStatusEnum::DRAFT, 'Files for request');
        $this->mock(FileService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('upload')->once()->andReturn('org-test/site-requests/request/file.pdf');
            $mock->shouldReceive('temporaryUrl')->twice()->andReturn('https://private.example/file.pdf');
            $mock->shouldReceive('delete')->once()->andReturn(true);
        });

        $uploaded = $this->withHeaders($context->mobileAuthHeaders())
            ->post("/api/v1/mobile/site-requests/{$siteRequest->id}/files", ['file' => UploadedFile::fake()->create('site-photo.pdf', 32, 'application/pdf')]);
        $uploaded->assertCreated()->assertJsonPath('data.name', 'site-photo.pdf');
        $fileId = (int) $uploaded->json('data.id');

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson("/api/v1/mobile/site-requests/{$siteRequest->id}/files")
            ->assertOk()
            ->assertJsonPath('data.0.id', $fileId);

        $this->withHeaders($context->mobileAuthHeaders())
            ->deleteJson("/api/v1/mobile/site-requests/{$siteRequest->id}/files/{$fileId}")
            ->assertOk();
        $this->assertSoftDeleted('files', ['id' => $fileId]);
    }

    private function createSiteRequest(
        AdminApiTestContext $context,
        Project $project,
        SiteRequestStatusEnum $status,
        string $title
    ): SiteRequest {
        return SiteRequest::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'user_id' => $context->user->id,
            'title' => $title,
            'request_type' => SiteRequestTypeEnum::MATERIAL_REQUEST->value,
            'status' => $status->value,
            'priority' => SiteRequestPriorityEnum::MEDIUM->value,
            'material_name' => 'Concrete',
            'material_quantity' => 1,
            'material_unit' => 'm3',
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
            $mock->shouldReceive('getUserRoleSlugs')->andReturn(['foreman']);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
            $mock->shouldReceive('getUserPermissionsStructured')->andReturn([
                'system' => [],
                'modules' => [
                    'site-requests' => [
                        'site_requests.view',
                        'site_requests.create',
                        'site_requests.assign',
                        'site_requests.files.upload',
                        'site_requests.files.delete',
                        'site_requests.change_status',
                        'site_requests.approve',
                    ],
                ],
            ]);
        });
    }
}
