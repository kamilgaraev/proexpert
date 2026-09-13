<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Models\Contract;
use App\Models\Estimate;
use Illuminate\Support\Collection;

class EstimateContractIntegrationService
{
    public function __construct(
        protected EstimateCoverageService $coverageService
    ) {}

    public function createFromContract(Contract $contract, array $additionalData = []): Estimate
    {
        return $this->coverageService->createFromContract($contract, $additionalData);
    }

    public function validateContractAmount(
        Estimate $estimate,
        ?Contract $contract = null,
        bool $includeVat = false,
        ?string $rate = null
    ): array {
        return $this->coverageService->validateContractAmount($estimate, $contract, $includeVat, $rate);
    }

    public function linkToContract(Estimate $estimate, int $contractId, bool $includeVat = false, ?\App\Models\User $actor = null, ?string $rate = null): array
    {
        $contract = Contract::findOrFail($contractId);
        $this->coverageService->attachFullCoverage($contract, $estimate, $includeVat, $actor, $rate);

        return $this->coverageService->getCoverageForEstimate($estimate);
    }

    public function unlinkFromContract(Estimate $estimate, int $contractId, ?\App\Models\User $actor = null): array
    {
        $contract = Contract::query()->where('organization_id', $estimate->organization_id)
            ->where('project_id', $estimate->project_id)->findOrFail($contractId);
        $this->coverageService->detachCoverage($contract, $estimate, $actor);

        return $this->coverageService->getCoverageForEstimate($estimate);
    }

    public function getEstimatesByContract(Contract $contract): Collection
    {
        return $this->coverageService->getEstimatesByContract($contract);
    }

    public function syncContractAmount(Estimate $estimate): void
    {
        if (!$estimate->isApproved()) {
            return;
        }

        $this->coverageService->syncContractAmount($estimate);
    }

    public function getCoverage(Estimate $estimate): array
    {
        return $this->coverageService->getCoverageForEstimate($estimate);
    }
}
