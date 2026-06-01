<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\Contract;
use Illuminate\Support\Collection;

final class ProcurementChainResolver
{
    public function __construct(
        private readonly PurchaseOrderPaymentGateService $paymentGateService
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fromSiteRequest(SiteRequest $siteRequest): array
    {
        $siteRequest->loadMissing([
            'purchaseRequests.lines',
            'purchaseRequests.supplierRequests.proposals',
            'purchaseRequests.supplierRequests.proposalDecision.winningProposal',
            'purchaseRequests.purchaseOrders.items',
            'purchaseRequests.purchaseOrders.receipts.lines',
            'purchaseRequests.purchaseOrders.acceptedSupplierProposal',
        ]);

        $purchaseRequest = $siteRequest->purchaseRequests
            ->sortByDesc('id')
            ->first();

        return $this->graph($siteRequest, $purchaseRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromPurchaseRequest(PurchaseRequest $purchaseRequest): array
    {
        $purchaseRequest->loadMissing([
            'siteRequest',
            'lines',
            'supplierRequests.proposals',
            'supplierRequests.proposalDecision.winningProposal',
            'purchaseOrders.items',
            'purchaseOrders.receipts.lines',
            'purchaseOrders.acceptedSupplierProposal',
        ]);

        return $this->graph($purchaseRequest->siteRequest, $purchaseRequest);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromPurchaseOrder(PurchaseOrder $purchaseOrder): array
    {
        $purchaseOrder->loadMissing([
            'purchaseRequest.siteRequest',
            'purchaseRequest.lines',
            'purchaseRequest.supplierRequests.proposals',
            'purchaseRequest.supplierRequests.proposalDecision.winningProposal',
            'items',
            'receipts.lines',
            'receipts.warehouse',
            'acceptedSupplierProposal',
            'supplier',
            'externalSupplierContact',
            'supplierParty',
            'contract',
        ]);

        return $this->graph($purchaseOrder->purchaseRequest?->siteRequest, $purchaseOrder->purchaseRequest, $purchaseOrder);
    }

    /**
     * @return array<string, mixed>
     */
    public function fromPaymentDocument(PaymentDocument $paymentDocument): array
    {
        $purchaseOrder = $this->resolvePurchaseOrderFromPaymentDocument($paymentDocument);

        if ($purchaseOrder instanceof PurchaseOrder) {
            return $this->fromPurchaseOrder($purchaseOrder);
        }

        return [
            'site_request' => null,
            'purchase_request' => null,
            'supplier_requests' => collect(),
            'supplier_proposals' => collect(),
            'proposal_decision' => null,
            'selected_proposal' => null,
            'purchase_order' => null,
            'payment_documents' => collect([$paymentDocument]),
            'receipts' => collect(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fromPurchaseReceipt(PurchaseReceipt $purchaseReceipt): array
    {
        $purchaseReceipt->loadMissing('purchaseOrder');

        if (! $purchaseReceipt->purchaseOrder instanceof PurchaseOrder) {
            return [
                'site_request' => null,
                'purchase_request' => null,
                'supplier_requests' => collect(),
                'supplier_proposals' => collect(),
                'proposal_decision' => null,
                'selected_proposal' => null,
                'purchase_order' => null,
                'payment_documents' => collect(),
                'receipts' => collect([$purchaseReceipt]),
            ];
        }

        return $this->fromPurchaseOrder($purchaseReceipt->purchaseOrder);
    }

    /**
     * @return array<string, mixed>
     */
    private function graph(
        ?SiteRequest $siteRequest,
        ?PurchaseRequest $purchaseRequest,
        ?PurchaseOrder $explicitOrder = null
    ): array {
        $purchaseOrder = $explicitOrder ?? $this->latestPurchaseOrder($purchaseRequest);
        $supplierRequests = $purchaseRequest?->supplierRequests ?? collect();
        $supplierProposals = $supplierRequests
            ->flatMap(static fn (SupplierRequest $request): Collection => $request->proposals)
            ->values();
        $proposalDecision = $supplierRequests
            ->map(static fn (SupplierRequest $request): ?SupplierProposalDecision => $request->proposalDecision)
            ->filter()
            ->sortByDesc('id')
            ->first();
        $selectedProposal = $proposalDecision?->winningProposal
            ?? $purchaseOrder?->acceptedSupplierProposal;
        $paymentDocuments = $purchaseOrder instanceof PurchaseOrder
            ? $this->paymentGateService->linkedDocuments($purchaseOrder)
            : collect();
        $receipts = $purchaseOrder?->receipts ?? collect();

        return [
            'site_request' => $siteRequest,
            'purchase_request' => $purchaseRequest,
            'supplier_requests' => $supplierRequests->values(),
            'supplier_proposals' => $supplierProposals,
            'proposal_decision' => $proposalDecision,
            'selected_proposal' => $selectedProposal,
            'purchase_order' => $purchaseOrder,
            'payment_documents' => $paymentDocuments,
            'receipts' => $receipts->values(),
        ];
    }

    private function latestPurchaseOrder(?PurchaseRequest $purchaseRequest): ?PurchaseOrder
    {
        if (! $purchaseRequest instanceof PurchaseRequest) {
            return null;
        }

        return $purchaseRequest->purchaseOrders
            ->sortByDesc('id')
            ->first();
    }

    private function resolvePurchaseOrderFromPaymentDocument(PaymentDocument $paymentDocument): ?PurchaseOrder
    {
        $metadata = is_array($paymentDocument->metadata) ? $paymentDocument->metadata : [];
        $purchaseOrderId = $metadata['purchase_order_id'] ?? null;

        if (is_numeric($purchaseOrderId)) {
            return PurchaseOrder::query()
                ->where('organization_id', $paymentDocument->organization_id)
                ->find((int) $purchaseOrderId);
        }

        if (
            $paymentDocument->source_type === Contract::class
            && $paymentDocument->source_id !== null
        ) {
            $orders = PurchaseOrder::query()
                ->where('organization_id', $paymentDocument->organization_id)
                ->where('contract_id', $paymentDocument->source_id)
                ->limit(2)
                ->get();

            if ($orders->count() === 1) {
                return $orders->first();
            }
        }

        return null;
    }
}
