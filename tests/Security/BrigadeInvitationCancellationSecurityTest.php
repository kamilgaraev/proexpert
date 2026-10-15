<?php

declare(strict_types=1);

namespace Tests\Security;

use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeInvitation;
use App\BusinessModules\Contractors\Brigades\Domain\Models\BrigadeProfile;
use App\BusinessModules\Contractors\Brigades\Support\BrigadeStatuses;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Models\UserRoleAssignment;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Module;
use App\Models\Project;
use App\Models\User;
use App\Services\Entitlements\OrganizationEntitlementService;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class BrigadeInvitationCancellationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $entitlements = \Mockery::mock(OrganizationEntitlementService::class);
        $entitlements->shouldReceive('getEffectiveModules')->andReturn(collect([new Module(['slug' => 'brigades'])]));
        $this->app->instance(OrganizationEntitlementService::class, $entitlements);
    }

    public function test_organization_admin_can_cancel_a_created_invitation_without_reviving_it(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'organization_admin');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        self::assertTrue(app(AuthorizationService::class)->canCurrent($context->user, 'brigades.invitations.create', ['organization_id' => $context->organization->id]));
        $brigade = $this->brigade();
        $created = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/brigades/invitations', [
            'brigade_id' => $brigade->id, 'project_id' => $project->id,
            'expires_at' => now()->addWeek()->toDateString(),
        ])->assertCreated();
        $url = '/api/v1/admin/brigades/invitations/'.$created->json('data.id').'/cancel';
        $this->postJson($url)->assertOk()->assertJsonPath('data.status', BrigadeStatuses::INVITATION_CANCELLED);
        $this->postJson($url)->assertUnprocessable();
        self::assertSame(BrigadeStatuses::INVITATION_CANCELLED, BrigadeInvitation::findOrFail($created->json('data.id'))->status);
    }

    public function test_project_manager_can_cancel_only_the_invitation_in_its_project_context(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'admin_viewer');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $otherProject = Project::factory()->create(['organization_id' => $context->organization->id]);
        AuthorizationContext::getProjectContext($otherProject->id, $context->organization->id);
        UserRoleAssignment::assignRole($context->user, 'project_manager',
            AuthorizationContext::getProjectContext($project->id, $context->organization->id));
        self::assertTrue(app(AuthorizationService::class)->canCurrent($context->user, 'brigades.invitations.create', [
            'organization_id' => $context->organization->id, 'project_id' => $project->id, 'strict_project_scope' => true,
        ]));
        $brigade = $this->brigade();
        $created = $this->withHeaders($context->authHeaders())->postJson('/api/v1/admin/brigades/invitations', [
            'brigade_id' => $brigade->id, 'project_id' => $project->id,
            'expires_at' => now()->addWeek()->toDateString(),
        ])->assertCreated();
        $this->postJson('/api/v1/admin/brigades/invitations/'.$created->json('data.id').'/cancel')
            ->assertOk()->assertJsonPath('data.status', BrigadeStatuses::INVITATION_CANCELLED);
        $other = BrigadeInvitation::query()->create([
            'brigade_id' => $brigade->id, 'project_id' => $otherProject->id,
            'contractor_organization_id' => $context->organization->id, 'status' => BrigadeStatuses::INVITATION_PENDING,
        ]);
        $this->postJson('/api/v1/admin/brigades/invitations/'.$other->id.'/cancel')->assertForbidden();
        self::assertSame(BrigadeStatuses::INVITATION_PENDING, $other->fresh()->status);
    }

    private function brigade(): BrigadeProfile
    {
        $owner = User::factory()->create();

        return BrigadeProfile::query()->create([
            'owner_user_id' => $owner->id, 'name' => 'Бригада', 'slug' => 'invitation-regression-brigade',
            'contact_person' => $owner->name, 'contact_email' => $owner->email, 'contact_phone' => '+79990000000',
            'team_size' => 1, 'verification_status' => BrigadeStatuses::PROFILE_APPROVED,
        ]);
    }
}
