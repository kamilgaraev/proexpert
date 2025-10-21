<?php

namespace App\BusinessModules\Features\BudgetEstimates\Services\Integration;

use App\Models\Estimate;
use App\Models\Contract;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateService;
use App\BusinessModules\Features\BudgetEstimates\Services\EstimateItemService;
use App\Repositories\EstimateRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EstimateContractIntegrationService
{
    public function __construct(
        protected EstimateRepository $estimateRepository,
        protected EstimateService $estimateService,
        protected EstimateItemService $itemService
    ) {}

    public function createFromContract(Contract $contract, array $additionalData = []): Estimate
    {
        return DB::transaction(function () use ($contract, $additionalData) {
            $estimateData = array_merge([
                'organization_id' => $contract->organization_id,
                'project_id' => $contract->project_id,
                'contract_id' => $contract->id,
                'name' => 'Смета по договору ' . $contract->number,
                'type' => 'contractual',
                'estimate_date' => now(),
            ], $additionalData);
            
            $estimate = $this->estimateService->create($estimateData);
            
            return $estimate;
        });
    }

    public function validateContractAmount(Estimate $estimate): array
    {
        if (!$estimate->contract_id) {
            return [
                'valid' => true,
                'message' => 'Смета не привязана к договору'
            ];
        }
        
        $contract = $estimate->contract;
        $difference = $estimate->total_amount - $contract->total_amount;
        $percentageDifference = $contract->total_amount > 0 
            ? round(($difference / $contract->total_amount) * 100, 2) 
            : 0;
        
        $isValid = abs($percentageDifference) <= 5;
        
        return [
            'valid' => $isValid,
            'estimate_amount' => (float) $estimate->total_amount,
            'contract_amount' => (float) $contract->total_amount,
            'difference' => (float) $difference,
            'percentage_difference' => $percentageDifference,
            'message' => $isValid 
                ? 'Сумма сметы соответствует сумме договора' 
                : 'Сумма сметы отличается от суммы договора более чем на 5%',
        ];
    }

    public function linkToContract(Estimate $estimate, int $contractId): void
    {
        $contract = Contract::findOrFail($contractId);
        
        if ($contract->organization_id !== $estimate->organization_id) {
            throw new \Exception('Договор принадлежит другой организации');
        }
        
        $estimate->update([
            'contract_id' => $contractId,
            'project_id' => $contract->project_id,
        ]);
    }

    public function getEstimatesByContract(Contract $contract): Collection
    {
        return $this->estimateRepository->getByContract($contract->id);
    }

    public function syncContractAmount(Estimate $estimate): void
    {
        if (!$estimate->contract_id || !$estimate->isApproved()) {
            return;
        }
        
        $contract = $estimate->contract;
        $contract->update([
            'total_amount' => $estimate->total_amount
        ]);
    }
}

