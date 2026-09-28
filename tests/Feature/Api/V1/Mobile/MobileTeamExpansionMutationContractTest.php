<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Contractors\Brigades\BrigadesModule;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeRequest;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeResponse;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Models\Module;
use App\Models\Project;
use App\Modules\Core\AccessController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class MobileTeamExpansionMutationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_can_create_a_brigade_request_and_read_it_back(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->registerBrigadeModule($context->organization->id);

        $created = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/team-expansion/brigade-requests', [
                'project_id' => $project->id,
                'title' => 'Mobile request for concrete works',
                'description' => 'A crew is needed to pour the foundation slab.',
                'specialization_name' => 'Concrete works',
                'city' => 'Moscow',
                'team_size_min' => 4,
                'team_size_max' => 6,
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.item.title', 'Mobile request for concrete works')
            ->assertJsonPath('data.item.project_id', $project->id)
            ->assertJsonPath('data.item.status', BrigadeStatuses::REQUEST_OPEN)
            ->assertJsonPath('data.item.specialization_name', 'Concrete works')
            ->assertJsonPath('data.item.team_size_min', 4)
            ->assertJsonPath('data.item.team_size_max', 6);

        $requestId = (int) $created->json('data.item.id');
        $this->assertDatabaseHas('brigade_requests', [
            'id' => $requestId,
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'title' => 'Mobile request for concrete works',
            'status' => BrigadeStatuses::REQUEST_OPEN,
        ]);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/team-expansion/brigade-requests?project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.title', 'Mobile request for concrete works');
    }

    public function test_mobile_can_invite_an_approved_brigade_and_read_the_invitation_back(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $context->user->id,
            'name' => 'Approved concrete crew',
            'slug' => 'approved-concrete-crew-'.$context->organization->id,
            'description' => 'Concrete construction team',
            'team_size' => 6,
            'contact_person' => 'Crew manager',
            'contact_phone' => '+79990000000',
            'contact_email' => 'crew-'.$context->organization->id.'@example.test',
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        $this->registerBrigadeModule($context->organization->id);

        $created = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/team-expansion/brigade-invitations', [
                'brigade_id' => $brigade->id,
                'project_id' => $project->id,
                'message' => 'Please join the project next week.',
            ]);

        $created->assertCreated()
            ->assertJsonPath('data.item.brigade_id', $brigade->id)
            ->assertJsonPath('data.item.project_id', $project->id)
            ->assertJsonPath('data.item.contractor_organization_id', $context->organization->id)
            ->assertJsonPath('data.item.message', 'Please join the project next week.')
            ->assertJsonPath('data.item.status', BrigadeStatuses::INVITATION_PENDING);

        $invitationId = (int) $created->json('data.item.id');
        $this->assertDatabaseHas('brigade_invitations', [
            'id' => $invitationId,
            'brigade_id' => $brigade->id,
            'project_id' => $project->id,
            'contractor_organization_id' => $context->organization->id,
            'status' => BrigadeStatuses::INVITATION_PENDING,
        ]);

        $this->withHeaders($context->mobileAuthHeaders())
            ->getJson('/api/v1/mobile/team-expansion/brigade-invitations?project_id='.$project->id)
            ->assertOk()
            ->assertJsonPath('data.0.id', $invitationId)
            ->assertJsonPath('data.0.message', 'Please join the project next week.');

        $duplicate = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson('/api/v1/mobile/team-expansion/brigade-invitations', [
                'brigade_id' => $brigade->id,
                'project_id' => $project->id,
                'message' => 'A duplicate pending invitation must not be created.',
            ]);

        $duplicate->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'http_409');
        $this->assertDatabaseCount('brigade_invitations', 1);
    }

    public function test_mobile_mutations_reject_invalid_payloads_and_projects_outside_the_actor_scope(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'web_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $foreignContext = AdminApiTestContext::create(roleSlug: 'web_admin');
        $foreignProject = Project::factory()->create(['organization_id' => $foreignContext->organization->id]);
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $foreignContext->user->id,
            'name' => 'Approved external crew',
            'slug' => 'approved-external-crew-'.$foreignContext->organization->id,
            'contact_person' => 'External manager',
            'contact_phone' => '+79990000001',
            'contact_email' => 'external-'.$foreignContext->organization->id.'@example.test',
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        $this->registerBrigadeModule($context->organization->id);
        $headers = $context->mobileAuthHeaders();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/team-expansion/brigade-requests', [
                'project_id' => $project->id,
                'title' => 'Invalid team bounds',
                'description' => 'The maximum is smaller than the minimum.',
                'team_size_min' => 5,
                'team_size_max' => 3,
            ])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/team-expansion/brigade-requests', [
                'project_id' => $foreignProject->id,
                'title' => 'Foreign project request',
                'description' => 'This project belongs to another organization.',
            ])
            ->assertUnprocessable();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/team-expansion/brigade-invitations', [
                'brigade_id' => $brigade->id,
                'project_id' => $foreignProject->id,
                'message' => 'A cross-organization project must be rejected.',
            ])
            ->assertUnprocessable();

        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => 'assigned_projects',
        ]);
        $outOfScopeResponse = $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/team-expansion/brigade-requests', [
                'project_id' => $project->id,
                'title' => 'Unassigned project request',
                'description' => 'This same-organization project is outside the user scope.',
            ]);
        $outOfScopeResponse->assertNotFound(
            'Unexpected same-organization out-of-scope response: '.$outOfScopeResponse->getContent()
        );

        $this->assertDatabaseMissing('brigade_requests', ['title' => 'Invalid team bounds']);
        $this->assertDatabaseMissing('brigade_requests', ['title' => 'Foreign project request']);
        $this->assertDatabaseMissing('brigade_requests', ['title' => 'Unassigned project request']);
        $this->assertDatabaseMissing('brigade_invitations', [
            'brigade_id' => $brigade->id,
            'project_id' => $foreignProject->id,
        ]);
    }

    public function test_mobile_organization_owner_can_approve_a_response_and_create_a_project_assignment(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_owner');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $context->organization->users()->updateExistingPivot($context->user->id, [
            'project_access_mode' => 'assigned_projects',
        ]);
        $context->user->assignedProjects()->attach($project->id, [
            'role' => 'member',
            'is_active' => true,
        ]);
        $request = BrigadeRequest::query()->create([
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'title' => 'Request with a pending response',
            'description' => 'Approve a crew for this assigned project.',
            'status' => BrigadeStatuses::REQUEST_OPEN,
        ]);
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $context->user->id,
            'name' => 'Response crew for assignment',
            'slug' => 'response-crew-assignment-'.$context->organization->id,
            'contact_person' => 'Crew manager',
            'contact_phone' => '+79990000003',
            'contact_email' => 'response-assignment-'.$context->organization->id.'@example.test',
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        $response = BrigadeResponse::query()->create([
            'request_id' => $request->id,
            'brigade_id' => $brigade->id,
            'cover_message' => 'Available from Monday.',
            'status' => BrigadeStatuses::RESPONSE_PENDING,
        ]);
        $this->registerBrigadeModule($context->organization->id);

        $approved = $this->withHeaders($context->mobileAuthHeaders())
            ->postJson("/api/v1/mobile/team-expansion/brigade-requests/{$request->id}/responses/{$response->id}/approve", []);

        $approved->assertOk()
            ->assertJsonPath('data.response.id', $response->id)
            ->assertJsonPath('data.response.status', BrigadeStatuses::RESPONSE_APPROVED)
            ->assertJsonPath('data.assignment.brigade_id', $brigade->id)
            ->assertJsonPath('data.assignment.project_id', $project->id)
            ->assertJsonPath('data.assignment.contractor_organization_id', $context->organization->id)
            ->assertJsonPath('data.assignment.status', BrigadeStatuses::ASSIGNMENT_PLANNED)
            ->assertJsonPath('data.assignment.source_id', $response->id);

        $this->assertDatabaseHas('brigade_responses', [
            'id' => $response->id,
            'status' => BrigadeStatuses::RESPONSE_APPROVED,
        ]);
        $this->assertDatabaseHas('brigade_requests', [
            'id' => $request->id,
            'status' => BrigadeStatuses::REQUEST_IN_REVIEW,
        ]);
        $this->assertDatabaseHas('brigade_project_assignments', [
            'brigade_id' => $brigade->id,
            'project_id' => $project->id,
            'contractor_organization_id' => $context->organization->id,
            'source_id' => $response->id,
            'status' => BrigadeStatuses::ASSIGNMENT_PLANNED,
        ]);
    }

    public function test_mobile_mutations_require_the_matching_actor_permissions(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'foreman');
        $context->organization->users()->updateExistingPivot($context->user->id, ['is_owner' => false]);
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $request = BrigadeRequest::query()->create([
            'contractor_organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'title' => 'Existing brigade request',
            'description' => 'Needs one crew.',
            'status' => BrigadeStatuses::REQUEST_OPEN,
        ]);
        $brigade = BrigadeProfile::query()->create([
            'owner_user_id' => $context->user->id,
            'name' => 'Response crew',
            'slug' => 'response-crew-'.$context->organization->id,
            'contact_person' => 'Crew manager',
            'contact_phone' => '+79990000002',
            'contact_email' => 'response-'.$context->organization->id.'@example.test',
            'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
        $response = BrigadeResponse::query()->create([
            'request_id' => $request->id,
            'brigade_id' => $brigade->id,
            'status' => BrigadeStatuses::RESPONSE_PENDING,
        ]);
        $this->registerBrigadeModule($context->organization->id);
        $headers = $context->mobileAuthHeaders();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/team-expansion/brigade-requests', [
                'project_id' => $project->id,
                'title' => 'Unauthorized request',
                'description' => 'This role cannot create requests.',
            ])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson('/api/v1/mobile/team-expansion/brigade-invitations', [
                'brigade_id' => $brigade->id,
                'project_id' => $project->id,
            ])
            ->assertForbidden();

        $this->withHeaders($headers)
            ->postJson("/api/v1/mobile/team-expansion/brigade-requests/{$request->id}/responses/{$response->id}/approve", [])
            ->assertForbidden();

        $this->assertDatabaseHas('brigade_responses', [
            'id' => $response->id,
            'status' => BrigadeStatuses::RESPONSE_PENDING,
        ]);
    }

    private function registerBrigadeModule(int $organizationId): void
    {
        $module = new BrigadesModule;
        $manifest = $module->getManifest();

        Module::query()->updateOrCreate(
            ['slug' => $module->getSlug()],
            [
                'name' => $module->getName(),
                'version' => $module->getVersion(),
                'type' => $module->getType()->value,
                'billing_model' => $module->getBillingModel()->value,
                'category' => $manifest['category'] ?? 'collaboration',
                'description' => $module->getDescription(),
                'features' => $module->getFeatures(),
                'permissions' => $module->getPermissions(),
                'dependencies' => $module->getDependencies(),
                'conflicts' => $module->getConflicts(),
                'limits' => $module->getLimits(),
                'class_name' => $module::class,
                'config_file' => 'ModuleList/features/brigades.json',
                'display_order' => $manifest['display_order'] ?? 0,
                'is_active' => true,
                'is_system_module' => false,
            ]
        );

        $this->app->make(AccessController::class)->clearAccessCache($organizationId);
        self::assertTrue($this->app->make(AccessController::class)->hasModuleAccess($organizationId, 'brigades'));
    }
}
