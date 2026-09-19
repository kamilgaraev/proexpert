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
        $participants = $this->activeParticipants($project, $role);

        return $participants->count() === 1 ? $participants->first() : null;
    }

    public function activeParticipants(Project $project, ProjectOrganizationRole $role): \Illuminate\Database\Eloquent\Collection
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

        return Organization::query()->whereIn('id', $organizationIds)->orderBy('id')->get();
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
        ContractSideTypeEnum $sideType,
        ?int $superiorOrganizationId = null,
        ?string $direction = null,
    ): bool {
        if ($direction === 'expense') {
            return false;
        }
        $assignedRole = $this->assignedRole($project, $organizationId);

        if ($sideType === ContractSideTypeEnum::CONTRACT && $assignedRole === ProjectOrganizationRole::CONTRACTOR) {
            $generalContractor = $this->selectedActiveParticipant($project, $this->superiorRole($project, $sideType), $superiorOrganizationId);

            return $generalContractor instanceof Organization && (int) $generalContractor->id !== $organizationId;
        }

        if ($sideType === ContractSideTypeEnum::SUBCONTRACT && $assignedRole === ProjectOrganizationRole::SUBCONTRACTOR) {
            $contractor = $this->selectedActiveParticipant($project, ProjectOrganizationRole::CONTRACTOR, $superiorOrganizationId);

            return $contractor instanceof Organization && (int) $contractor->id !== $organizationId;
        }

        return false;
    }

    public function selectedActiveParticipant(Project $project, ProjectOrganizationRole $role, ?int $organizationId): ?Organization
    {
        if ($organizationId === null || in_array($role, [ProjectOrganizationRole::GENERAL_CONTRACTOR, ProjectOrganizationRole::CUSTOMER], true)) {
            $participant = $this->uniqueActiveParticipant($project, $role);

            return $participant !== null && ($organizationId === null || (int) $participant->id === $organizationId)
                ? $participant : null;
        }

        return $this->assignedRole($project, $organizationId) === $role
            ? Organization::query()->find($organizationId)
            : null;
    }

    public function superiorRole(Project $project, ContractSideTypeEnum $type): ProjectOrganizationRole
    {
        return match ($type) {
            ContractSideTypeEnum::GENERAL_CONTRACT => ProjectOrganizationRole::CUSTOMER,
            ContractSideTypeEnum::SUBCONTRACT => ProjectOrganizationRole::CONTRACTOR,
            default => $project->contracting_scheme === 'direct'
                ? ProjectOrganizationRole::CUSTOMER : ProjectOrganizationRole::GENERAL_CONTRACTOR,
        };
    }
}
