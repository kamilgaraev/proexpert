<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateContractIntegrationService;
use App\Models\Estimate;
use App\Models\Contract;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateContractController extends Controller
{
    public function __construct(
        protected EstimateContractIntegrationService $integrationService
    ) {}

    /**
     * Создать смету из договора
     */
    public function createFromContract(Request $request, int $projectId, int $contractId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $contract = Contract::where('id', $contractId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'copy_specifications' => 'boolean',
        ]);

        $estimate = $this->integrationService->createFromContract($contract, [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return AdminResponse::success(
            ['estimate_id' => $estimate->id],
            __('contract.estimate_created'),
            201
        );
    }

    /**
     * Привязать смету к договору
     */
    public function linkContract(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validated = $request->validate([
            'contract_id' => 'required|exists:contracts,id',
        ]);

        try {
            $this->integrationService->linkToContract($estimate, $validated['contract_id']);

            return AdminResponse::success(null, __('contract.estimate_linked'));
        } catch (\Exception $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    /**
     * Отвязать смету от договора
     */
    public function unlinkContract(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $estimate->update([
            'contract_id' => null,
        ]);

        return AdminResponse::success(null, __('contract.estimate_unlinked'));
    }

    /**
     * Валидация суммы сметы относительно договора
     */
    public function validateContractAmount(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $estimate = Estimate::where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validation = $this->integrationService->validateContractAmount($estimate);

        return AdminResponse::success([
            'is_valid' => $validation['valid'],
            'estimate_total' => $validation['estimate_amount'],
            'contract_total' => $validation['contract_amount'],
            'difference' => $validation['difference'],
            'difference_percentage' => $validation['percentage_difference'],
        ]);
    }

    /**
     * Получить список смет по договору
     */
    public function getEstimatesByContract(Request $request, int $projectId, int $contractId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');
        
        $contract = Contract::where('id', $contractId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $estimates = $this->integrationService->getEstimatesByContract($contract);

        return AdminResponse::success(
            $estimates->map(function ($estimate) {
                return [
                    'id' => $estimate->id,
                    'name' => $estimate->name,
                    'total_amount' => (float) $estimate->total_amount,
                    'created_at' => $estimate->created_at->toISOString(),
                    'status' => $estimate->status,
                ];
            })
        );
    }
}

