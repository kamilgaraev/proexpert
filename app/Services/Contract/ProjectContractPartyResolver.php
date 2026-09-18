<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractSideTypeEnum;
use App\Enums\ProjectOrganizationRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectOrganization;

class ProjectContractPartyResolver
{
    public function assignedRole(Project $project, int $organizationId): ?ProjectOrganizationRole
    {
        $pivot = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->first();

        if (! $pivot instanceof ProjectOrganization) {
            return null;
        }

        $roleValue = $pivot->getRawOriginal('role_new') ?: $pivot->getRawOriginal('role');

        return ProjectOrganizationRole::tryFrom((string) $roleValue);
    }

    public function uniqueActiveParticipant(Project $project, ProjectOrganizationRole $role): ?Organization
    {
        $organizationIds = ProjectOrganization::query()
            ->useWritePdo()
            ->where('project_id', $project->id)
            ->where('is_active', true)
            ->get()
            ->filter(function (ProjectOrganization $participantRecord) use ($role): bool {
                $roleValue = $participantRecord->getRawOriginal('role_new') ?: $participantRecord->getRawOriginal('role');

                return ProjectOrganizationRole::tryFrom((string) $roleValue) === $role;
            })
            ->pluck('organization_id')
            ->unique()
            ->values();

        if ($organizationIds->count() !== 1) {
            return null;
        }

        $organization = Organization::query()->find($organizationIds->first());

        return $organization instanceof Organization ? $organization : null;
    }

    public function resolveSuperiorOrganization(
        Project $project,
        ProjectOrganizationRole $role,
        Organization $fallback
    ): Organization {
        $unique = $this->uniqueActiveParticipant($project, $role);
        if ($unique instanceof Organization) {
            return $unique;
        }

        if ($role === ProjectOrganizationRole::GENERAL_CONTRACTOR) {
            $projectOwner = Organization::query()->find($project->organization_id);
            if ($projectOwner instanceof Organization) {
                return $projectOwner;
            }
        }

        return $fallback;
    }

    public function shouldAutofillSelfAsContractor(
        Project $project,
        int $organizationId,
        ContractSideTypeEnum $sideType
    ): bool {
        $assignedRole = $this->assignedRole($project, $organizationId);

        if ($sideType === ContractSideTypeEnum::CONTRACT && $assignedRole === ProjectOrganizationRole::CONTRACTOR) {
            $generalContractor = $this->uniqueActiveParticipant($project, ProjectOrganizationRole::GENERAL_CONTRACTOR)
                ?? Organization::query()->find($project->organization_id);

            return $generalContractor instanceof Organization && (int) $generalContractor->id !== $organizationId;
        }

        if ($sideType === ContractSideTypeEnum::SUBCONTRACT && $assignedRole === ProjectOrganizationRole::SUBCONTRACTOR) {
            $contractor = $this->uniqueActiveParticipant($project, ProjectOrganizationRole::CONTRACTOR);

            return $contractor instanceof Organization && (int) $contractor->id !== $organizationId;
        }

        return false;
    }
}
