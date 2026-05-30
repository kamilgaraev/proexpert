<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Domain\Services;

use App\BusinessModules\ContractorMarketplace\Domain\Enums\MarketplaceProfileStatus;
use App\BusinessModules\ContractorMarketplace\Domain\Models\MarketplaceContractorProfile;
use App\Models\ContractorInvitation;
use App\Models\Organization;
use App\Services\Logging\LoggingService;

class MarketplaceNetworkService
{
    public function __construct(
        private readonly LoggingService $logging
    ) {
    }

    public function bootstrapDraftProfileFromInvitation(ContractorInvitation $invitation): ?MarketplaceContractorProfile
    {
        if ($invitation->status !== ContractorInvitation::STATUS_ACCEPTED) {
            return null;
        }

        $organization = $invitation->invitedOrganization;

        if (! $organization instanceof Organization || ! $organization->is_active) {
            return null;
        }

        if (! $this->hasActiveOwner($organization)) {
            $this->logging->business('marketplace.network.bootstrap.skipped_without_owner', [
                'contractor_invitation_id' => $invitation->id,
                'invited_organization_id' => $invitation->invited_organization_id,
            ], 'warning');

            return null;
        }

        $profile = MarketplaceContractorProfile::query()->firstOrCreate(
            ['organization_id' => $organization->id],
            [
                'status' => MarketplaceProfileStatus::DRAFT,
                'display_name' => $organization->name,
                'base_city' => $organization->city,
                'availability_status' => 'hidden',
                'verification_level' => $organization->is_verified ? 'basic' : 'none',
                'is_visible_in_marketplace' => false,
                'metadata' => [],
            ]
        );

        $metadata = $profile->metadata ?? [];
        $metadata['network_bootstrap'] = [
            'source' => 'contractor_invitation',
            'contractor_invitation_id' => $invitation->id,
            'inviting_organization_id' => $invitation->organization_id,
            'invited_organization_id' => $invitation->invited_organization_id,
            'accepted_at' => $invitation->accepted_at?->toISOString(),
            'bootstrapped_at' => now()->toISOString(),
        ];

        $profile->forceFill(['metadata' => $metadata])->save();

        $this->logging->business('marketplace.network.profile_bootstrapped', [
            'contractor_invitation_id' => $invitation->id,
            'profile_id' => $profile->id,
            'organization_id' => $organization->id,
            'profile_status' => $profile->status?->value,
            'visible' => $profile->is_visible_in_marketplace,
        ]);

        return $profile->fresh();
    }

    private function hasActiveOwner(Organization $organization): bool
    {
        return $organization->owners()
            ->wherePivot('is_active', true)
            ->exists();
    }
}
