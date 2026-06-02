<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use Illuminate\Database\Eloquent\Builder;

class ContractAccessService
{
    public function findAccessible(int $contractId, int $organizationId, ?int $projectId = null): ?Contract
    {
        $query = Contract::query()->whereKey($contractId);
        $this->applyAccessibleScope($query, $organizationId);

        $contract = $query->first();

        if (!$contract) {
            return null;
        }

        $contract->load([
            'organization',
            'contractor.sourceOrganization',
            'supplier',
        ]);

        $isOwnerOrganization = (int) $contract->organization_id === (int) $organizationId;
        $isContractorOrganization = !$contract->is_self_execution
            && $contract->contractor !== null
            && (int) ($contract->contractor->source_organization_id ?? 0) === (int) $organizationId;

        if (!$isOwnerOrganization && !$isContractorOrganization) {
            return null;
        }

        if ($projectId !== null) {
            $belongsToProject = $contract->is_multi_project
                ? $contract->projects()->where('projects.id', $projectId)->exists()
                : (int) $contract->project_id === (int) $projectId;

            if (!$belongsToProject) {
                return null;
            }
        }

        $contract->load([
            'project',
            'project.organization',
            'project.organizations',
            'projects',
            'agreements',
            'specifications',
        ]);

        if ($projectId !== null) {
            $contract->load([
                'performanceActs' => fn ($query) => $query->where('project_id', $projectId),
                'performanceActs.completedWorks',
            ]);
        } else {
            $contract->load([
                'performanceActs',
                'performanceActs.completedWorks',
            ]);
        }

        return $contract;
    }

    public function applyAccessibleScope(Builder $query, int $organizationId): Builder
    {
        return $query->where(function (Builder $scope) use ($organizationId): void {
            $scope->where('contracts.organization_id', $organizationId)
                ->orWhereHas('contractor', function (Builder $contractorQuery) use ($organizationId): void {
                    $contractorQuery->where('source_organization_id', $organizationId);
                });
        });
    }

    public function canAccess(Contract $contract, int $organizationId, ?int $projectId = null): bool
    {
        $contract->loadMissing('contractor');

        $isOwnerOrganization = (int) $contract->organization_id === (int) $organizationId;
        $isContractorOrganization = !$contract->is_self_execution
            && $contract->contractor !== null
            && (int) ($contract->contractor->source_organization_id ?? 0) === (int) $organizationId;

        if (!$isOwnerOrganization && !$isContractorOrganization) {
            return false;
        }

        if ($projectId === null) {
            return true;
        }

        return $this->belongsToProject($contract, $projectId);
    }

    private function belongsToProject(Contract $contract, int $projectId): bool
    {
        return $contract->is_multi_project
            ? $contract->projects()->where('projects.id', $projectId)->exists()
            : (int) $contract->project_id === (int) $projectId;
    }
}
