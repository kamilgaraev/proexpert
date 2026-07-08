<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Controllers;

use App\BusinessModules\Core\Payments\Http\Resources\PaymentDocumentResource;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\Http\Resources\ProcurementChainResource;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Services\ProcurementChainService;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentDocumentService;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Http\Controllers\Controller;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

use function trans_message;

final class ProcurementChainController extends Controller
{
    public function __construct(
        private readonly ProcurementChainService $chainService,
        private readonly PurchaseOrderPaymentDocumentService $paymentDocumentService
    ) {
    }

    public function siteRequest(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $siteRequest = SiteRequest::forOrganization($organizationId)->find($id);

            if (! $siteRequest instanceof SiteRequest) {
                return AdminResponse::error(trans_message('procurement.chain.not_found'), 404);
            }

            return $this->chainResponse(
                new ProcurementChainResource($this->chainService->forSiteRequest($siteRequest, $request->user()))
            );
        } catch (\Throwable $e) {
            Log::error('procurement.chain.site_request.error', [
                'site_request_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.chain.load_error'), 500);
        }
    }

    public function purchaseRequest(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseRequest = PurchaseRequest::forOrganization($organizationId)->find($id);

            if (! $purchaseRequest instanceof PurchaseRequest) {
                return AdminResponse::error(trans_message('procurement.chain.not_found'), 404);
            }

            return $this->chainResponse(
                new ProcurementChainResource($this->chainService->forPurchaseRequest($purchaseRequest, $request->user()))
            );
        } catch (\Throwable $e) {
            Log::error('procurement.chain.purchase_request.error', [
                'purchase_request_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.chain.load_error'), 500);
        }
    }

    public function purchaseOrder(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseOrder = PurchaseOrder::forOrganization($organizationId)->find($id);

            if (! $purchaseOrder instanceof PurchaseOrder) {
                return AdminResponse::error(trans_message('procurement.chain.not_found'), 404);
            }

            return $this->chainResponse(
                new ProcurementChainResource($this->chainService->forPurchaseOrder($purchaseOrder, $request->user()))
            );
        } catch (\Throwable $e) {
            Log::error('procurement.chain.purchase_order.error', [
                'purchase_order_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.chain.load_error'), 500);
        }
    }

    public function paymentDocument(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $paymentDocument = PaymentDocument::forOrganization($organizationId)->find($id);

            if (! $paymentDocument instanceof PaymentDocument) {
                return AdminResponse::error(trans_message('procurement.chain.not_found'), 404);
            }

            return $this->chainResponse(
                new ProcurementChainResource($this->chainService->forPaymentDocument($paymentDocument, $request->user()))
            );
        } catch (\Throwable $e) {
            Log::error('procurement.chain.payment_document.error', [
                'payment_document_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.chain.load_error'), 500);
        }
    }

    public function purchaseReceipt(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseReceipt = PurchaseReceipt::query()
                ->where('organization_id', $organizationId)
                ->find($id);

            if (! $purchaseReceipt instanceof PurchaseReceipt) {
                return AdminResponse::error(trans_message('procurement.chain.not_found'), 404);
            }

            return $this->chainResponse(
                new ProcurementChainResource($this->chainService->forPurchaseReceipt($purchaseReceipt, $request->user()))
            );
        } catch (\Throwable $e) {
            Log::error('procurement.chain.purchase_receipt.error', [
                'purchase_receipt_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.chain.load_error'), 500);
        }
    }

    public function createOrOpenPaymentDocument(Request $request, int $id): JsonResponse
    {
        try {
            $organizationId = (int) $request->attributes->get('current_organization_id');
            $purchaseOrder = PurchaseOrder::forOrganization($organizationId)
                ->with([
                    'contract',
                    'purchaseRequest.siteRequest',
                    'supplier',
                    'externalSupplierContact',
                    'supplierParty',
                ])
                ->find($id);

            if (! $purchaseOrder instanceof PurchaseOrder) {
                return AdminResponse::error(trans_message('procurement.purchase_orders.not_found'), 404);
            }

            $budgetDimensions = $this->paymentDocumentBudgetDimensions($request);
            $result = $this->paymentDocumentService->createOrOpen(
                $purchaseOrder,
                $request->user()?->id,
                $budgetDimensions
            );
            $document = $result['document']->fresh([
                'payerOrganization',
                'payerContractor',
                'payeeOrganization',
                'payeeContractor',
                'project',
                'siteRequests',
            ]);

            return AdminResponse::success(
                new PaymentDocumentResource($document ?? $result['document']),
                $result['created']
                    ? trans_message('procurement.chain.payment_document.created')
                    : trans_message('procurement.chain.payment_document.opened'),
                $result['created'] ? 201 : 200
            );
        } catch (\DomainException|\InvalidArgumentException $e) {
            return AdminResponse::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('procurement.chain.payment_document.create.error', [
                'purchase_order_id' => $id,
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
            ]);

            return AdminResponse::error(trans_message('procurement.chain.payment_document.create_error'), 500);
        }
    }

    private function chainResponse(ProcurementChainResource $resource): JsonResponse
    {
        return AdminResponse::success(
            $resource,
            trans_message('procurement.chain.loaded')
        );
    }

    /**
     * @return array<string, int|string|null>
     */
    private function paymentDocumentBudgetDimensions(Request $request): array
    {
        $dimensions = [];

        foreach (['budget_article_id', 'responsibility_center_id'] as $field) {
            $value = $request->input($field);

            if (is_scalar($value) || $value === null) {
                $dimensions[$field] = $value;
            }
        }

        return $dimensions;
    }
}
