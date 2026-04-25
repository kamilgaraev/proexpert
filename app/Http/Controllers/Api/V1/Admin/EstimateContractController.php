<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\BusinessModules\Features\BudgetEstimates\Services\Integration\EstimateContractIntegrationService;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateCoverageResource;
use App\Http\Resources\Api\V1\Admin\Estimate\EstimateResource;
use App\Http\Responses\AdminResponse;
use App\Models\Contract;
use App\Models\Estimate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EstimateContractController extends Controller
{
    public function __construct(
        protected EstimateContractIntegrationService $integrationService
    ) {}

    public function createFromContract(Request $request, int $projectId, int $contractId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $contract = Contract::query()
            ->where('id', $contractId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'type' => 'nullable|in:local,object,summary,contractual',
            'estimate_date' => 'nullable|date',
        ]);

        $estimate = $this->integrationService->createFromContract($contract, [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'] ?? 'contractual',
            'estimate_date' => $validated['estimate_date'] ?? now(),
        ]);

        return AdminResponse::success(
            new EstimateResource($estimate->fresh(['project'])),
            trans_message('contract.estimate_created'),
            201
        );
    }

    public function linkContract(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimate = Estimate::query()
            ->where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validated = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
            'include_vat' => 'sometimes|boolean',
        ]);

        try {
            $coverage = $this->integrationService->linkToContract(
                $estimate,
                (int) $validated['contract_id'],
                (bool) ($validated['include_vat'] ?? false)
            );

            return AdminResponse::success(
                new EstimateCoverageResource($coverage),
                trans_message('contract.estimate_linked')
            );
        } catch (\Throwable $e) {
            return AdminResponse::error($e->getMessage(), 422);
        }
    }

    public function unlinkContract(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimate = Estimate::query()
            ->where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $validated = $request->validate([
            'contract_id' => 'required|integer|exists:contracts,id',
        ]);

        $coverage = $this->integrationService->unlinkFromContract($estimate, (int) $validated['contract_id']);

        return AdminResponse::success(
            new EstimateCoverageResource($coverage),
            trans_message('contract.estimate_unlinked')
        );
    }

    public function validateContractAmount(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimate = Estimate::query()
            ->where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        $contractId = $request->query('contract_id');
        $validation = $this->integrationService->validateContractAmount(
            $estimate,
            $contractId ? (int) $contractId : null
        );

        return AdminResponse::success([
            'is_valid' => $validation['valid'],
            'estimate_total' => $validation['estimate_amount'],
            'covered_amount' => $validation['covered_amount'],
            'contract_total' => $validation['contract_amount'],
            'difference' => $validation['difference'],
            'difference_percentage' => $validation['percentage_difference'],
            'coverage_status' => $validation['coverage_status'],
            'message' => $validation['message'],
        ]);
    }

    public function getCoverage(Request $request, int $projectId, int $estimateId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $estimate = Estimate::query()
            ->where('id', $estimateId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        return AdminResponse::success(
            new EstimateCoverageResource($this->integrationService->getCoverage($estimate))
        );
    }

    public function getEstimatesByContract(Request $request, int $projectId, int $contractId): JsonResponse
    {
        $organizationId = $request->attributes->get('current_organization_id');

        $contract = Contract::query()
            ->where('id', $contractId)
            ->where('project_id', $projectId)
            ->where('organization_id', $organizationId)
            ->firstOrFail();

        return AdminResponse::success($this->integrationService->getEstimatesByContract($contract));
    }
}
