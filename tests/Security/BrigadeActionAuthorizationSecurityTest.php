<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProjectAssignment;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Domain\Services\BrigadeWorkflowService;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\OrganizationCustomRole;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Exceptions\BusinessLogicException;
use App\Models\Module;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class BrigadeActionAuthorizationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $entitlements = Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'brigades'])]));
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);
    }

    public function test_owner_can_perform_each_contractor_action(): void
    {
        $actor = $this->context('organization_owner');
        $data = $this->fixtures($actor);
        $statuses = $this->performActions($actor, $data);

        self::assertSame([200, 200, 200, 201, 201], $statuses);
        self::assertSame(BrigadeStatuses::ASSIGNMENT_ACTIVE, $data['assignment']->fresh()->status);
        self::assertSame(BrigadeStatuses::REQUEST_CLOSED, $data['close_request']->fresh()->status);
        self::assertSame(BrigadeStatuses::RESPONSE_APPROVED, $data['response']->fresh()->status);
        self::assertSame(BrigadeStatuses::REQUEST_IN_REVIEW, $data['request']->fresh()->status);
        self::assertSame(2, BrigadeProjectAssignment::query()->count());
        self::assertSame(1, BrigadeInvitation::query()->count());
    }

    public function test_viewer_cannot_perform_any_contractor_action(): void
    {
        $actor = $this->context('admin_viewer');
        $data = $this->fixtures($actor);
        foreach (['assignments.edit', 'requests.close', 'responses.approve', 'requests.create', 'invitations.create'] as $action) {
            self::assertFalse(app(AuthorizationService::class)->canCurrent($actor->user, 'brigades.'.$action, [
                'organization_id' => $actor->organization->id,
            ]));
        }

        self::assertSame([403, 403, 403, 403, 403], $this->performActions($actor, $data));
        self::assertSame(BrigadeStatuses::ASSIGNMENT_PLANNED, $data['assignment']->fresh()->status);
        self::assertSame(BrigadeStatuses::REQUEST_OPEN, $data['close_request']->fresh()->status);
        self::assertSame(BrigadeStatuses::RESPONSE_PENDING, $data['response']->fresh()->status);
        self::assertSame(BrigadeStatuses::REQUEST_OPEN, $data['request']->fresh()->status);
        self::assertSame(1, BrigadeProjectAssignment::query()->count());
        self::assertSame(2, BrigadeRequest::query()->count());
        self::assertSame(0, BrigadeInvitation::query()->count());
    }

    #[DataProvider('actions')]
    public function test_direct_service_requires_the_matching_action(string $action): void
    {
        $actor = $this->context('admin_viewer');
        $data = $this->fixtures($actor);
        $counts = $this->counts();

        $this->expectException(AuthorizationException::class);
        try {
            $this->callAction($actor, $action, $data);
        } finally {
            $this->assertUnchanged($data, $counts);
        }
    }

    #[DataProvider('actions')]
    public function test_project_action_permission_does_not_extend_to_a_sibling_project(string $action): void
    {
        $actor = $this->context('admin_viewer');
        $first = $this->fixtures($actor);
        $second = $this->fixtures($actor);
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, ['project_access_mode' => 'assigned_projects']);
        foreach ([$first['project'], $second['project']] as $project) {
            $project->users()->attach($actor->user->id, ['role' => 'member', 'is_active' => true]);
            AuthorizationContext::getProjectContext($project->id, $actor->organization->id);
            self::assertTrue(app(UserProjectAccessService::class)->canAccessCurrentOrganizationProject($actor->user, $project));
        }
        $this->grant($actor, [$this->permission($action)], $first['project']);
        self::assertFalse(app(AuthorizationService::class)->canCurrent($actor->user, $this->permission($action), [
            'organization_id' => $actor->organization->id,
        ]));
        self::assertNotNull($this->callAction($actor, $action, $first));
        $counts = $this->counts();

        $this->expectException(AuthorizationException::class);
        try {
            $this->callAction($actor, $action, $second);
        } finally {
            $this->assertUnchanged($second, $counts);
        }
    }

    #[DataProvider('actions')]
    public function test_organization_action_permission_does_not_override_project_assignment(string $action): void
    {
        $actor = $this->context('admin_viewer');
        $data = $this->fixtures($actor);
        $this->grant($actor, [$this->permission($action)]);
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, ['project_access_mode' => 'assigned_projects']);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($actor->user, $this->permission($action), [
            'organization_id' => $actor->organization->id,
        ]));
        $counts = $this->counts();

        $this->expectException(AuthorizationException::class);
        try {
            $this->callAction($actor, $action, $data);
        } finally {
            $this->assertUnchanged($data, $counts);
        }
    }

    #[DataProvider('actions')]
    public function test_inactive_membership_prevents_each_mutation(string $action): void
    {
        $actor = $this->context('organization_owner');
        $data = $this->fixtures($actor);
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, ['is_active' => false]);
        $counts = $this->counts();

        $this->expectException(AuthorizationException::class);
        try {
            $this->callAction($actor, $action, $data);
        } finally {
            $this->assertUnchanged($data, $counts);
        }
    }

    public static function actions(): array
    {
        return [['assignment'], ['close'], ['approve'], ['request'], ['invitation']];
    }

    #[DataProvider('creationActions')]
    public function test_project_only_permission_cannot_create_an_organization_level_record(string $action): void
    {
        $actor = $this->context('admin_viewer');
        $data = $this->fixtures($actor);
        $this->grant($actor, [$this->permission($action)], $data['project']);
        $data['project'] = null;
        $counts = $this->counts();

        $this->expectException(AuthorizationException::class);
        try {
            $this->callAction($actor, $action, $data);
        } finally {
            self::assertSame($counts, $this->counts());
        }
    }

    public static function creationActions(): array
    {
        return [['request'], ['invitation']];
    }

    public function test_exact_organization_permissions_allow_records_without_a_project(): void
    {
        $actor = $this->context('admin_viewer');
        $data = $this->fixtures($actor);
        $this->grant($actor, ['brigades.requests.create', 'brigades.invitations.create']);
        $data['project'] = null;

        self::assertNull($this->callAction($actor, 'request', $data)->project_id);
        self::assertNull($this->callAction($actor, 'invitation', $data)->project_id);
    }

    public function test_revoked_action_permission_is_not_reused_from_cache(): void
    {
        $actor = $this->context('admin_viewer');
        $data = $this->fixtures($actor);
        $assignment = $this->grant($actor, ['brigades.requests.create']);
        self::assertTrue($actor->user->hasPermission('brigades.requests.create', ['organization_id' => $actor->organization->id]));
        $assignment->update(['is_active' => false]);
        $counts = $this->counts();

        $this->expectException(AuthorizationException::class);
        try {
            $this->callAction($actor, 'request', $data);
        } finally {
            $this->assertUnchanged($data, $counts);
        }
    }

    public function test_service_preserves_token_organization_and_filters_assignment_fields(): void
    {
        $actor = $this->context('organization_owner');
        $data = $this->fixtures($actor);
        $otherOrganization = Organization::factory()->create();
        $foreignProject = Project::factory()->create(['organization_id' => $otherOrganization->id]);
        $actor->user->organizations()->attach($otherOrganization->id, ['is_active' => true]);
        $actor->user->update(['current_organization_id' => $otherOrganization->id]);
        $actor->user->current_organization_id = $actor->organization->id;
        $service = app(BrigadeWorkflowService::class);

        $updated = $service->updateAssignment($actor->user, $actor->organization->id, $data['assignment']->id, [
            'status' => BrigadeStatuses::ASSIGNMENT_ACTIVE, 'project_id' => $foreignProject->id,
            'contractor_organization_id' => $otherOrganization->id, 'brigade_id' => 999999,
            'source_type' => 'forged-source', 'source_id' => 999999,
        ]);
        self::assertSame($data['project']->id, $updated->project_id);
        self::assertSame($actor->organization->id, $updated->contractor_organization_id);
        self::assertSame($data['brigade']->id, $updated->brigade_id);
        self::assertNull($updated->source_type);
        $created = $service->createRequest($actor->user, $actor->organization->id, [
            'project_id' => $data['project']->id, 'title' => 'Bound organization', 'description' => 'Bound organization',
            'contractor_organization_id' => $otherOrganization->id, 'status' => BrigadeStatuses::REQUEST_CLOSED,
        ]);
        self::assertSame($actor->organization->id, $created->contractor_organization_id);
        self::assertSame(BrigadeStatuses::REQUEST_OPEN, $created->status);
        self::assertSame($otherOrganization->id, $actor->user->fresh()->current_organization_id);

        $count = BrigadeRequest::query()->count();
        $this->expectException(AuthorizationException::class);
        try {
            $service->createRequest($actor->user, $otherOrganization->id, [
                'project_id' => $foreignProject->id, 'title' => 'Wrong selected organization', 'description' => 'Denied',
            ]);
        } finally {
            self::assertSame($count, BrigadeRequest::query()->count());
        }
    }

    public function test_approval_binds_the_response_to_its_request_and_cannot_repeat(): void
    {
        $actor = $this->context('organization_owner');
        $first = $this->fixtures($actor);
        $second = $this->fixtures($actor);
        $counts = $this->counts();
        $service = app(BrigadeWorkflowService::class);
        try {
            $service->approveResponse($actor->user, $actor->organization->id, $first['request']->id, $second['response']->id);
            self::fail('Expected a response from another request to be rejected.');
        } catch (ModelNotFoundException) {
            $this->assertUnchanged($first, $counts);
            $this->assertUnchanged($second, $counts);
        }
        $approval = $service->approveResponse($actor->user, $actor->organization->id, $first['request']->id, $first['response']->id);
        self::assertSame($first['project']->id, $approval->assignment->project_id);
        $countsAfter = $this->counts();

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionCode(409);
        try {
            $service->approveResponse($actor->user, $actor->organization->id, $first['request']->id, $first['response']->id);
        } finally {
            $this->assertUnchanged($second, $countsAfter);
        }
    }

    public function test_brigade_owner_acceptance_needs_no_contractor_role_and_reloads_invitation_state(): void
    {
        $actor = $this->context('organization_owner');
        $data = $this->fixtures($actor);
        $owner = User::query()->findOrFail($data['brigade']->owner_user_id);
        $invitation = BrigadeInvitation::query()->create([
            'contractor_organization_id' => $actor->organization->id, 'project_id' => $data['project']->id,
            'brigade_id' => $data['brigade']->id, 'status' => BrigadeStatuses::INVITATION_PENDING,
        ]);
        $forged = clone $invitation;
        $forged->status = BrigadeStatuses::INVITATION_ACCEPTED;
        $service = app(BrigadeWorkflowService::class);
        try {
            $service->createAssignmentFromInvitation($forged, $owner);
            self::fail('Expected persisted invitation state to be checked.');
        } catch (ValidationException) {
            self::assertSame(1, BrigadeProjectAssignment::query()->count());
        }
        $accepted = $service->respondToInvitation($owner, $invitation->id, BrigadeStatuses::INVITATION_ACCEPTED);
        $assignment = $service->createAssignmentFromInvitation($accepted, $owner);
        self::assertSame($actor->organization->id, $assignment->contractor_organization_id);
        self::assertSame(2, BrigadeProjectAssignment::query()->count());
        self::assertFalse($owner->organizations()->exists());

        $this->expectException(ModelNotFoundException::class);
        try {
            $service->createAssignmentFromInvitation($accepted, $actor->user);
        } finally {
            self::assertSame(2, BrigadeProjectAssignment::query()->count());
        }
    }

    private function permission(string $action): string
    {
        return 'brigades.'.match ($action) {
            'assignment' => 'assignments.edit', 'close' => 'requests.close', 'approve' => 'responses.approve',
            'request' => 'requests.create', 'invitation' => 'invitations.create',
        };
    }

    private function grant(AdminApiTestContext $actor, array $permissions, ?Project $project = null): UserRoleAssignment
    {
        $role = OrganizationCustomRole::query()->create([
            'organization_id' => $actor->organization->id, 'name' => 'Brigade operator',
            'created_by' => $actor->user->id, 'system_permissions' => ['admin.access'],
            'module_permissions' => ['brigades' => $permissions], 'interface_access' => ['admin'], 'is_active' => true,
        ]);

        return UserRoleAssignment::assignRole($actor->user, $role->slug,
            $project ? AuthorizationContext::getProjectContext($project->id, $actor->organization->id)
                : AuthorizationContext::getOrganizationContext($actor->organization->id), UserRoleAssignment::TYPE_CUSTOM);
    }

    private function callAction(AdminApiTestContext $actor, string $action, array $data): mixed
    {
        $service = app(BrigadeWorkflowService::class);
        $organizationId = $actor->organization->id;

        return match ($action) {
            'assignment' => $service->updateAssignment($actor->user, $organizationId, $data['assignment']->id, ['status' => BrigadeStatuses::ASSIGNMENT_ACTIVE]),
            'close' => $service->closeRequest($actor->user, $organizationId, $data['close_request']->id),
            'approve' => $service->approveResponse($actor->user, $organizationId, $data['request']->id, $data['response']->id),
            'request' => $service->createRequest($actor->user, $organizationId, [
                'project_id' => $data['project']?->id, 'title' => 'Service crew request', 'description' => 'Authorized request',
            ]),
            'invitation' => $service->createInvitation($actor->user, $organizationId, [
                'project_id' => $data['project']?->id, 'brigade_id' => $data['brigade']->id,
            ]),
        };
    }

    private function counts(): array
    {
        return [BrigadeProjectAssignment::query()->count(), BrigadeRequest::query()->count(), BrigadeInvitation::query()->count()];
    }

    private function assertUnchanged(array $data, array $counts): void
    {
        self::assertSame(BrigadeStatuses::ASSIGNMENT_PLANNED, $data['assignment']->fresh()->status);
        self::assertSame(BrigadeStatuses::REQUEST_OPEN, $data['close_request']->fresh()->status);
        self::assertSame(BrigadeStatuses::RESPONSE_PENDING, $data['response']->fresh()->status);
        self::assertSame(BrigadeStatuses::REQUEST_OPEN, $data['request']->fresh()->status);
        self::assertSame($counts, $this->counts());
    }

    private function performActions(AdminApiTestContext $actor, array $data): array
    {
        $headers = $actor->authHeaders();
        $assignment = $this->patchJson('/api/v1/admin/brigades/assignments/'.$data['assignment']->id, [
            'status' => BrigadeStatuses::ASSIGNMENT_ACTIVE,
        ], $headers);
        $close = $this->postJson('/api/v1/admin/brigades/requests/'.$data['close_request']->id.'/close', [], $headers);
        $approve = $this->postJson('/api/v1/admin/brigades/requests/'.$data['request']->id.'/responses/'.$data['response']->id.'/approve', [], $headers);
        $create = $this->postJson('/api/v1/admin/brigades/requests', [
            'project_id' => $data['project']->id, 'title' => 'New crew request', 'description' => 'A crew is needed',
        ], $headers);
        $invite = $this->postJson('/api/v1/admin/brigades/invitations', [
            'project_id' => $data['project']->id, 'brigade_id' => $data['brigade']->id,
        ], $headers);

        return [$assignment->status(), $close->status(), $approve->status(), $create->status(), $invite->status()];
    }

    private function context(string $role): AdminApiTestContext
    {
        $actor = AdminApiTestContext::create(roleSlug: $role);
        $actor->user->organizations()->updateExistingPivot($actor->organization->id, [
            'is_owner' => $role === 'organization_owner', 'project_access_mode' => 'all_projects',
        ]);

        return $actor;
    }

    private function fixtures(AdminApiTestContext $actor): array
    {
        $project = Project::factory()->create(['organization_id' => $actor->organization->id]);
        $owner = User::factory()->create();
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $owner->id, 'name' => 'Security test crew', 'slug' => 'security-actions-'.$owner->id,
            'contact_person' => $owner->name, 'contact_email' => $owner->email, 'contact_phone' => '+79990000000',
            'team_size' => 2, 'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        $request = $this->request($actor, $project);
        $closeRequest = $this->request($actor, $project);
        $response = BrigadeResponse::query()->create([
            'request_id' => $request->id, 'brigade_id' => $brigade->id,
            'status' => BrigadeStatuses::RESPONSE_PENDING,
        ]);
        $assignment = BrigadeProjectAssignment::query()->create([
            'contractor_organization_id' => $actor->organization->id, 'project_id' => $project->id,
            'brigade_id' => $brigade->id, 'status' => BrigadeStatuses::ASSIGNMENT_PLANNED,
        ]);

        return ['project' => $project, 'brigade' => $brigade, 'request' => $request,
            'close_request' => $closeRequest, 'response' => $response, 'assignment' => $assignment];
    }

    private function request(AdminApiTestContext $actor, ?Project $project): BrigadeRequest
    {
        return BrigadeRequest::query()->create([
            'contractor_organization_id' => $actor->organization->id, 'project_id' => $project?->id,
            'title' => 'Crew request fixture', 'description' => 'Crew request security fixture',
            'status' => BrigadeStatuses::REQUEST_OPEN, 'published_at' => now(),
        ]);
    }
}
