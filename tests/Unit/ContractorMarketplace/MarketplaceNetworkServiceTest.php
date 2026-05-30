<?php

declare(strict_types=1);

namespace Tests\Unit\ContractorMarketplace;

use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\BusinessModules\ContractorMarketplace\Domain\Services\MarketplaceNetworkService;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarketplaceNetworkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepted_invitation_bootstraps_draft_profile_for_invited_organization(): void
    {
        [$invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy] = $this->createInvitationContext();
        $invitation = $this->createAcceptedInvitation($invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy);

        $profile = app(MarketplaceNetworkService::class)->bootstrapDraftProfileFromInvitation($invitation);

        $this->assertInstanceOf(MarketplaceContractorProfile::class, $profile);
        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $invitedOrganization->id,
            'status' => 'draft',
            'display_name' => $invitedOrganization->name,
            'availability_status' => 'hidden',
            'is_visible_in_marketplace' => false,
        ]);
        $this->assertSame('contractor_invitation', $profile->metadata['network_bootstrap']['source']);
        $this->assertSame($invitation->id, $profile->metadata['network_bootstrap']['contractor_invitation_id']);
    }

    public function test_duplicate_bootstrap_keeps_profile_unpublished_and_single(): void
    {
        [$invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy] = $this->createInvitationContext();
        $invitation = $this->createAcceptedInvitation($invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy);

        app(MarketplaceNetworkService::class)->bootstrapDraftProfileFromInvitation($invitation);
        app(MarketplaceNetworkService::class)->bootstrapDraftProfileFromInvitation($invitation);

        $this->assertSame(1, MarketplaceContractorProfile::query()
            ->where('organization_id', $invitedOrganization->id)
            ->count());
        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $invitedOrganization->id,
            'status' => 'draft',
            'is_visible_in_marketplace' => false,
        ]);
    }

    public function test_missing_active_owner_skips_profile_bootstrap(): void
    {
        $invitingOrganization = Organization::factory()->verified()->create();
        $invitedOrganization = Organization::factory()->verified()->create();
        $invitingUser = $this->createOrganizationUser($invitingOrganization);
        $invitation = ContractorInvitation::query()->create([
            'organization_id' => $invitingOrganization->id,
            'invited_organization_id' => $invitedOrganization->id,
            'invited_by_user_id' => $invitingUser->id,
            'status' => ContractorInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);

        $profile = app(MarketplaceNetworkService::class)->bootstrapDraftProfileFromInvitation($invitation);

        $this->assertNull($profile);
        $this->assertDatabaseMissing('marketplace_contractor_profiles', [
            'organization_id' => $invitedOrganization->id,
        ]);
    }

    public function test_profile_bootstrap_does_not_create_referral_reward(): void
    {
        [$invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy] = $this->createInvitationContext();
        $invitation = $this->createAcceptedInvitation($invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy);

        app(MarketplaceNetworkService::class)->bootstrapDraftProfileFromInvitation($invitation);

        $this->assertDatabaseHas('marketplace_contractor_profiles', [
            'organization_id' => $invitedOrganization->id,
            'status' => 'draft',
        ]);
        $this->assertDatabaseMissing('contractor_referral_rewards', [
            'contractor_invitation_id' => $invitation->id,
        ]);
    }

    /**
     * @return array{0: Organization, 1: Organization, 2: User, 3: User}
     */
    private function createInvitationContext(): array
    {
        $invitingOrganization = Organization::factory()->verified()->create();
        $invitedOrganization = Organization::factory()->verified()->create();
        $invitingUser = $this->createOrganizationUser($invitingOrganization);
        $acceptedBy = $this->createOrganizationUser($invitedOrganization);

        return [$invitingOrganization, $invitedOrganization, $invitingUser, $acceptedBy];
    }

    private function createOrganizationUser(Organization $organization): User
    {
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

    private function createAcceptedInvitation(
        Organization $organization,
        Organization $invitedOrganization,
        User $invitedBy,
        User $acceptedBy
    ): ContractorInvitation {
        return ContractorInvitation::query()->create([
            'organization_id' => $organization->id,
            'invited_organization_id' => $invitedOrganization->id,
            'invited_by_user_id' => $invitedBy->id,
            'accepted_by_user_id' => $acceptedBy->id,
            'status' => ContractorInvitation::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }
}
