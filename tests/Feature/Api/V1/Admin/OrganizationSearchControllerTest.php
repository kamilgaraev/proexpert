<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ContractorType;
use App\Enums\OrganizationCapability;
use App\Models\Contractor;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

class OrganizationSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_search_excludes_current_and_inactive_organizations_and_decorates_availability(): void
    {
        $this->allowAdminAccess();
        $context = AdminApiTestContext::create([], [
            'name' => 'Current Builder',
            'city' => 'Kazan',
        ]);
        $targetOrganization = Organization::factory()->verified()->create([
            'name' => 'Alpha Contractor',
            'city' => 'Kazan',
            'capabilities' => [OrganizationCapability::SUBCONTRACTING->value],
            'primary_business_type' => OrganizationCapability::SUBCONTRACTING->value,
        ]);
        Organization::factory()->inactive()->create([
            'name' => 'Inactive Contractor',
            'city' => 'Kazan',
        ]);

        $invitation = $this->createInvitation($context->organization, $targetOrganization);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/organizations/search?city=Kazan&sort_by=name&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $targetOrganization->id);
        $response->assertJsonPath('data.0.availability_status.can_invite', false);
        $response->assertJsonPath('data.0.availability_status.existing_invitation.id', $invitation->id);
        $response->assertJsonPath('data.0.primary_business_type', OrganizationCapability::SUBCONTRACTING->value);
        $response->assertJsonPath('data.0.interaction_modes.0', 'project_participant');

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertNotContains('Current Builder', $names);
        $this->assertNotContains('Inactive Contractor', $names);
    }

    public function test_search_filters_out_invited_and_existing_contractors_when_requested(): void
    {
        $this->allowAdminAccess();
        $context = AdminApiTestContext::create([], [
            'city' => 'Moscow',
        ]);
        $invitedOrganization = Organization::factory()->verified()->create([
            'name' => 'Already Invited',
            'city' => 'Moscow',
        ]);
        $contractorOrganization = Organization::factory()->verified()->create([
            'name' => 'Existing Contractor',
            'city' => 'Moscow',
        ]);
        $availableOrganization = Organization::factory()->verified()->create([
            'name' => 'Available Partner',
            'city' => 'Moscow',
        ]);

        $this->createInvitation($context->organization, $invitedOrganization);
        $this->createContractor($context->organization, $contractorOrganization);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/organizations/search?city=Moscow&exclude_invited=1&exclude_existing_contractors=1&sort_by=name&per_page=10');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 1);
        $response->assertJsonPath('data.0.id', $availableOrganization->id);
        $response->assertJsonPath('data.0.availability_status.can_invite', true);

        $names = collect($response->json('data'))->pluck('name')->all();

        $this->assertNotContains('Already Invited', $names);
        $this->assertNotContains('Existing Contractor', $names);
    }

    public function test_availability_reports_existing_contractor_reverse_invitation_and_mutual_status(): void
    {
        $this->allowAdminAccess();
        $context = AdminApiTestContext::create();
        $targetOrganization = Organization::factory()->verified()->create([
            'name' => 'Connected Contractor',
        ]);
        $contractor = $this->createContractor($context->organization, $targetOrganization);
        $reverseContractor = $this->createContractor($targetOrganization, $context->organization);
        $reverseInvitation = $this->createInvitation($targetOrganization, $context->organization);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/organizations/{$targetOrganization->id}/availability");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.can_invite', false);
        $response->assertJsonPath('data.existing_contractor.id', $contractor->id);
        $response->assertJsonPath('data.reverse_invitation.id', $reverseInvitation->id);
        $response->assertJsonPath('data.is_mutual', true);

        $this->assertDatabaseHas('contractors', [
            'id' => $reverseContractor->id,
            'organization_id' => $targetOrganization->id,
            'source_organization_id' => $context->organization->id,
        ]);
    }

    public function test_availability_blocks_invite_when_reverse_pending_invitation_exists(): void
    {
        $this->allowAdminAccess();
        $context = AdminApiTestContext::create();
        $targetOrganization = Organization::factory()->verified()->create([
            'name' => 'Pending Reverse Invite',
        ]);
        $reverseInvitation = $this->createInvitation($targetOrganization, $context->organization);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/organizations/{$targetOrganization->id}/availability");

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.can_invite', false);
        $response->assertJsonPath('data.reverse_invitation.id', $reverseInvitation->id);
        $response->assertJsonPath('data.existing_contractor', null);
    }

    public function test_validation_error_for_search_uses_readable_russian_message(): void
    {
        $this->allowAdminAccess();
        $context = AdminApiTestContext::create();

        $response = $this->withHeaders($context->authHeaders())
            ->getJson('/api/v1/admin/organizations/search?per_page=0');

        $response->assertStatus(422);
        $this->assertStringNotContainsString('Р', $response->json('message'));
    }

    private function allowAdminAccess(): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturn(true);
        });
    }

    private function createInvitation(
        Organization $organization,
        Organization $invitedOrganization,
        string $status = ContractorInvitation::STATUS_PENDING
    ): ContractorInvitation {
        return ContractorInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_organization_id' => $invitedOrganization->id,
            'invited_by_user_id' => $this->organizationUser($organization)->id,
            'status' => $status,
            'expires_at' => now()->addDays(7),
        ]);
    }

    private function createContractor(Organization $organization, Organization $sourceOrganization): Contractor
    {
        return Contractor::query()->create([
            'organization_id' => $organization->id,
            'source_organization_id' => $sourceOrganization->id,
            'name' => $sourceOrganization->name,
            'inn' => null,
            'contractor_type' => ContractorType::INVITED_ORGANIZATION->value,
            'connected_at' => now(),
        ]);
    }

    private function organizationUser(Organization $organization): User
    {
        $user = $organization->users()->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = User::factory()->create([
            'current_organization_id' => $organization->id,
        ]);

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
    }
}
