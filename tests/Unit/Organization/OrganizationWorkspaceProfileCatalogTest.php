<?php

declare(strict_types=1);

namespace Tests\Unit\Organization;

use App\Enums\ProjectOrganizationRole;
use App\Support\Organization\OrganizationWorkspaceProfileCatalog;
use PHPUnit\Framework\TestCase;

class OrganizationWorkspaceProfileCatalogTest extends TestCase
{
    public function test_it_resolves_single_capability_as_primary_profile(): void
    {
        $primaryProfile = OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
            ['subcontracting'],
            null
        );

        $this->assertSame('subcontracting', $primaryProfile);
    }

    public function test_it_drops_invalid_primary_profile_for_multi_capability_organization(): void
    {
        $primaryProfile = OrganizationWorkspaceProfileCatalog::normalizePrimaryProfile(
            ['general_contracting', 'design'],
            'materials_supply'
        );

        $this->assertNull($primaryProfile);
    }

    public function test_it_prioritizes_primary_profile_when_building_recommended_modules(): void
    {
        $recommendedModules = OrganizationWorkspaceProfileCatalog::recommendedModules(
            ['general_contracting', 'materials_supply'],
            'materials_supply'
        );

        $this->assertSame(
            [
                'procurement',
                'payments',
                'basic-warehouse',
                'catalog-management',
                'contractor-portal',
                'project-management',
                'contract-management',
                'schedule-management',
                'time-tracking',
            ],
            array_column($recommendedModules, 'value')
        );
    }

    public function test_it_keeps_observer_in_allowed_project_roles(): void
    {
        $allowedRoles = OrganizationWorkspaceProfileCatalog::allowedProjectRoles(['design']);

        $this->assertContains('designer', $allowedRoles);
        $this->assertContains(ProjectOrganizationRole::OBSERVER->value, $allowedRoles);
    }

    public function test_it_builds_workspace_profile_for_selected_capabilities(): void
    {
        $workspaceProfile = OrganizationWorkspaceProfileCatalog::buildWorkspaceProfile(
            ['general_contracting', 'subcontracting'],
            'general_contracting'
        );

        $this->assertSame('general_contracting', $workspaceProfile['primary_profile']);
        $this->assertCount(2, $workspaceProfile['workspace_options']);
        $this->assertSame(
            ['open_projects', 'open_invitations', 'open_modules', 'open_settings'],
            array_column($workspaceProfile['recommended_actions'], 'key')
        );
    }

    public function test_it_aggregates_interaction_modes_for_hybrid_profiles(): void
    {
        $interactionModes = OrganizationWorkspaceProfileCatalog::interactionModes([
            'facility_management',
            'materials_supply',
        ]);

        $this->assertSame(
            [
                OrganizationWorkspaceProfileCatalog::INTERACTION_PROJECT_PARTICIPANT,
                OrganizationWorkspaceProfileCatalog::INTERACTION_SERVICE_COUNTERPARTY,
                OrganizationWorkspaceProfileCatalog::INTERACTION_PROCUREMENT_COUNTERPARTY,
            ],
            $interactionModes
        );
    }
}
