<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Landing;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\Exceptions\BusinessLogicException;
use App\Models\Contractor;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Contractor\ContractorInvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ContractorInvitationAcceptTest extends TestCase
{
    use RefreshDatabase;

    public function test_accept_rejects_user_when_current_organization_does_not_match_invited_organization(): void
    {
        $invitingOrganization = Organization::factory()->verified()->create();
        $invitedOrganization = Organization::factory()->verified()->create();
        $currentOrganization = Organization::factory()->verified()->create();
        $invitingUser = $this->createOrganizationUser($invitingOrganization);
        $acceptingUser = $this->createOrganizationUser($invitedOrganization, [
            'current_organization_id' => $currentOrganization->id,
        ]);

        $currentOrganization->users()->attach($acceptingUser->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        $invitation = $this->createInvitation($invitingOrganization, $invitedOrganization, $invitingUser);

        try {
            app(ContractorInvitationService::class)->acceptInvitation($invitation->token, $acceptingUser);
            $this->fail('Invitation was accepted from a different organization context.');
        } catch (BusinessLogicException) {
            $this->assertSame(ContractorInvitation::STATUS_PENDING, $invitation->fresh()->status);
            $this->assertSame(0, Contractor::query()->count());
        }
    }

    public function test_accept_is_idempotent_for_already_accepted_invitation(): void
    {
        $invitingOrganization = Organization::factory()->verified()->create();
        $invitedOrganization = Organization::factory()->verified()->create();
        $invitingUser = $this->createOrganizationUser($invitingOrganization);
        $acceptingUser = $this->createOrganizationUser($invitedOrganization);
        $invitation = $this->createInvitation($invitingOrganization, $invitedOrganization, $invitingUser);

        $firstContractor = app(ContractorInvitationService::class)->acceptInvitation($invitation->token, $acceptingUser);
        $secondContractor = app(ContractorInvitationService::class)->acceptInvitation($invitation->token, $acceptingUser);

        $this->assertSame($firstContractor->id, $secondContractor->id);
        $this->assertSame(ContractorInvitation::STATUS_ACCEPTED, $invitation->fresh()->status);
        $this->assertSame(2, Contractor::query()->count());
        $this->assertDatabaseHas('contractors', [
            'organization_id' => $invitingOrganization->id,
            'source_organization_id' => $invitedOrganization->id,
            'contractor_invitation_id' => $invitation->id,
        ]);
        $this->assertDatabaseHas('contractors', [
            'organization_id' => $invitedOrganization->id,
            'source_organization_id' => $invitingOrganization->id,
            'contractor_invitation_id' => $invitation->id,
        ]);
        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $invitedOrganization->id,
            'status' => 'draft',
            'is_visible_in_marketplace' => false,
        ]);
        $this->assertSame(1, MarketplaceContractorProfile::query()
            ->where('organization_id', $invitedOrganization->id)
            ->count());
    }

    private function createOrganizationUser(Organization $organization, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'current_organization_id' => $organization->id,
        ], $attributes));

        $organization->users()->attach($user->id, [
            'is_owner' => true,
            'is_active' => true,
            'settings' => null,
        ]);

        return $user;
    }

    private function createInvitation(
        Organization $organization,
        Organization $invitedOrganization,
        User $invitedBy
    ): ContractorInvitation {
        return ContractorInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_organization_id' => $invitedOrganization->id,
            'invited_by_user_id' => $invitedBy->id,
            'status' => ContractorInvitation::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);
    }
}
