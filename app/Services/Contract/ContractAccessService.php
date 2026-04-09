<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;
use App\Repositories\Interfaces\ContractRepositoryInterface;

class ContractAccessService
{
    public function __construct(
        private readonly ContractRepositoryInterface $contractRepository,
    ) {
    }

    public function findAccessible(int $contractId, int $organizationId, ?int $projectId = null): ?Contract
    {
        $contract = $this->contractRepository->find($contractId);

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
}
