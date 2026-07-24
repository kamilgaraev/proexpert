<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Http\Resources\Api\V1\Admin\Contract\Agreement\SupplementaryAgreementResource;
use App\Http\Resources\Api\V1\Admin\Contract\ContractMiniResource;
use App\Http\Resources\Api\V1\Admin\Contract\ContractResource;
use App\Http\Resources\Api\V1\Admin\Contract\Payment\ContractPaymentResource;
use App\Http\Resources\Api\V1\Admin\Contract\PerformanceAct\ContractPerformanceActResource;
use App\Http\Resources\Api\V1\Admin\Contract\Specification\SpecificationResource;
use App\Models\Contract;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ContractReadService
{
    public function __construct(
        private readonly ContractAccessService $contractAccessService,
        private readonly ContractService $contractService,
    ) {
    }

    public function show(int $contractId, int $organizationId, ?int $projectId = null): Contract
    {
        return $this->contractAccessService->findAccessibleOrFail($contractId, $organizationId, $projectId);
    }

    public function analytics(int $contractId, int $organizationId, ?int $projectId = null): array
    {
        $contract = $this->contractAccessService->findAccessibleOrFail($contractId, $organizationId, $projectId);

        return [
            'contract_id' => $contract->id,
            'contract_number' => $contract->number,
            'total_amount' => (float) $contract->total_amount,
            'completed_works_amount' => $contract->completed_works_amount,
            'remaining_amount' => $contract->remaining_amount,
            'completion_percentage' => $contract->completion_percentage,
            'total_paid_amount' => $contract->total_paid_amount,
            'total_performed_amount' => $contract->total_performed_amount,
            'status' => $contract->status->value,
            'is_nearing_limit' => $contract->isNearingLimit(),
            'can_add_work' => $contract->canAddWork(0),
            'completed_works_count' => $contract->completedWorks()->count(),
            'confirmed_works_count' => $contract->completedWorks()->where('status', 'confirmed')->count(),
        ];
    }

    public function completedWorks(
        int $contractId,
        int $organizationId,
        ?int $projectId = null,
        int $perPage = 15
    ): LengthAwarePaginator {
        $perPage = max(1, min(100, $perPage));
        $contract = $this->contractAccessService->findAccessibleOrFail($contractId, $organizationId, $projectId);

        return $contract->completedWorks()
            ->with(['project', 'workType', 'user', 'materials.measurementUnit'])
            ->orderBy('completion_date', 'desc')
            ->paginate($perPage);
    }

    public function fullDetails(int $contractId, int $organizationId, ?int $projectId = null): array
    {
        $fullDetails = $this->contractService->getFullContractDetails($contractId, $organizationId, $projectId);
        $contract = $fullDetails['contract'];

        return [
            'contract' => new ContractResource($contract),
            'analytics' => $fullDetails['analytics'],
            'works_statistics' => $fullDetails['works_statistics'],
            'recent_works' => $fullDetails['recent_works'],
            'performance_acts' => $contract->relationLoaded('performanceActs')
                ? ContractPerformanceActResource::collection($contract->performanceActs)
                : [],
            'payments' => $contract->relationLoaded('payments')
                ? ContractPaymentResource::collection($contract->payments)
                : [],
            'child_contracts' => $contract->relationLoaded('childContracts')
                ? ContractMiniResource::collection($contract->childContracts)
                : [],
            'agreements' => $contract->relationLoaded('agreements')
                ? SupplementaryAgreementResource::collection($contract->agreements)
                : [],
            'specifications' => $contract->relationLoaded('specifications')
                ? SpecificationResource::collection($contract->specifications)
                : [],
        ];
    }
}
