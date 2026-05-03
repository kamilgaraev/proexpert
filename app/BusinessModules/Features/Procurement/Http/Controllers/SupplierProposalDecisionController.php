<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Features\Procurement\Http\Resources\SupplierProposalDecisionResource;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\Procurement\Services\SupplierProposalComparisonService;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

use function trans_message;

class SupplierProposalDecisionController extends Controller
{
    public function __construct(
        private readonly SupplierProposalComparisonService $service
    ) {}

    public function comparison(Request $request, int $supplierRequest): JsonResponse
    {
        try {
            $supplierRequestModel = $this->findSupplierRequest($request, $supplierRequest);

            return AdminResponse::success(
                $this->service->comparisonForRequest($supplierRequestModel),
                trans_message('procurement.proposal_decisions.comparison_fetched')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.supplier_requests.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('procurement.proposal_decisions.comparison.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'supplier_request_id' => $supplierRequest,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposal_decisions.comparison_error'), 500);
        }
    }

    public function purchaseRequestComparison(Request $request, int $purchaseRequest): JsonResponse
    {
        try {
            $purchaseRequestModel = $this->findPurchaseRequest($request, $purchaseRequest);

            return AdminResponse::success(
                $this->service->comparisonForPurchaseRequest($purchaseRequestModel),
                trans_message('procurement.proposal_decisions.comparison_fetched')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
        } catch (\Exception $e) {
            Log::error('procurement.proposal_decisions.purchase_request_comparison.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'purchase_request_id' => $purchaseRequest,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposal_decisions.comparison_error'), 500);
        }
    }

    public function select(Request $request, int $supplierRequest): JsonResponse
    {
        try {
            $validated = $request->validate([
                'supplier_proposal_id' => ['required', 'integer'],
                'decision_reason' => ['nullable', 'string', 'max:5000'],
            ]);

            $supplierRequestModel = $this->findSupplierRequest($request, $supplierRequest);
            $decision = $this->service->selectWinner(
                $supplierRequestModel,
                (int) $validated['supplier_proposal_id'],
                $validated['decision_reason'] ?? null,
                $request->user()?->id
            );

            return AdminResponse::success(
                new SupplierProposalDecisionResource($decision),
                trans_message('procurement.proposal_decisions.selected')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.supplier_requests.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.proposal_decisions.select.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'supplier_request_id' => $supplierRequest,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposal_decisions.select_error'), 500);
        }
    }

    public function selectForPurchaseRequest(Request $request, int $purchaseRequest): JsonResponse
    {
        try {
            $validated = $request->validate([
                'supplier_proposal_id' => ['required', 'integer'],
                'decision_reason' => ['nullable', 'string', 'max:5000'],
            ]);

            $purchaseRequestModel = $this->findPurchaseRequest($request, $purchaseRequest);
            $decision = $this->service->selectWinnerForPurchaseRequest(
                $purchaseRequestModel,
                (int) $validated['supplier_proposal_id'],
                $validated['decision_reason'] ?? null,
                $request->user()?->id
            );

            return AdminResponse::success(
                new SupplierProposalDecisionResource($decision),
                trans_message('procurement.proposal_decisions.selected')
            );
        } catch (ModelNotFoundException) {
            return AdminResponse::error(trans_message('procurement.purchase_requests.not_found'), 404);
        } catch (ValidationException $e) {
            return AdminResponse::error(trans_message('errors.validation_failed'), 422, $e->errors());
        } catch (\Exception $e) {
            Log::error('procurement.proposal_decisions.purchase_request_select.error', [
                'organization_id' => $request->attributes->get('current_organization_id'),
                'user_id' => $request->user()?->id,
                'purchase_request_id' => $purchaseRequest,
                'payload' => $request->all(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return AdminResponse::error(trans_message('procurement.proposal_decisions.select_error'), 500);
        }
    }

    private function findSupplierRequest(Request $request, int $supplierRequest): SupplierRequest
    {
        return SupplierRequest::query()
            ->forOrganization((int) $request->attributes->get('current_organization_id'))
            ->findOrFail($supplierRequest);
    }

    private function findPurchaseRequest(Request $request, int $purchaseRequest): PurchaseRequest
    {
        return PurchaseRequest::query()
            ->forOrganization((int) $request->attributes->get('current_organization_id'))
            ->findOrFail($purchaseRequest);
    }
}
