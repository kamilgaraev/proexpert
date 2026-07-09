<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Core\Payments\Enums\PaymentDocumentStatus;
use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainAction;
use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainBlocker;
use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainDocumentLink;
use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainStage;
use App\BusinessModules\Features\Procurement\DTOs\ProcurementChainSummary;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierProposalDecisionEnum;
use App\BusinessModules\Features\Procurement\Enums\SupplierRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\Procurement\Models\SupplierProposal;
use App\BusinessModules\Features\Procurement\Models\SupplierProposalDecision;
use App\BusinessModules\Features\Procurement\Models\SupplierRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\Models\User;
use Illuminate\Support\Collection;

use function trans_message;

final class ProcurementChainService
{
    /**
     * @var array<int, string>
     */
    private const STAGE_ORDER = [
        'site_request_created',
        'site_request_approved',
        'fulfillment_source_required',
        'warehouse_reserved',
        'warehouse_in_transit',
        'project_material_accepted',
        'purchase_request_created',
        'purchase_request_approved',
        'supplier_request_created',
        'supplier_request_sent',
        'commercial_proposal_received',
        'proposal_selected',
        'purchase_order_created',
        'payment_document_missing',
        'payment_document_draft',
        'payment_approval_required',
        'payment_approved',
        'payment_partially_registered',
        'payment_registered',
        'receipt_created',
        'warehouse_posted',
        'completed',
    ];

    public function __construct(
        private readonly ProcurementChainResolver $resolver,
        private readonly ProcurementChainActionResolver $actionResolver
    ) {
    }

    public function forSiteRequest(SiteRequest $siteRequest, ?User $actor = null): ProcurementChainSummary
    {
        return $this->build($this->resolver->fromSiteRequest($siteRequest), 'site-requests', $siteRequest->id, $actor);
    }

    public function forPurchaseRequest(PurchaseRequest $purchaseRequest, ?User $actor = null): ProcurementChainSummary
    {
        return $this->build($this->resolver->fromPurchaseRequest($purchaseRequest), 'purchase-requests', $purchaseRequest->id, $actor);
    }

    public function forPurchaseOrder(PurchaseOrder $purchaseOrder, ?User $actor = null): ProcurementChainSummary
    {
        return $this->build($this->resolver->fromPurchaseOrder($purchaseOrder), 'purchase-orders', $purchaseOrder->id, $actor);
    }

    public function forPaymentDocument(PaymentDocument $paymentDocument, ?User $actor = null): ProcurementChainSummary
    {
        return $this->build($this->resolver->fromPaymentDocument($paymentDocument), 'payment-documents', $paymentDocument->id, $actor);
    }

    public function forPurchaseReceipt(PurchaseReceipt $purchaseReceipt, ?User $actor = null): ProcurementChainSummary
    {
        return $this->build($this->resolver->fromPurchaseReceipt($purchaseReceipt), 'purchase-receipts', $purchaseReceipt->id, $actor);
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function build(array $graph, string $rootContext, int $rootId, ?User $actor): ProcurementChainSummary
    {
        $organizationId = $this->organizationId($graph);
        [$currentKey, $nextAction, $blockers] = $this->resolveCurrentState($graph, $actor, $organizationId);
        $linkedDocuments = $this->linkedDocuments($graph);
        $currentDocument = $this->documentForStage($currentKey, $linkedDocuments);
        $currentStage = $this->stage($currentKey, 'current', $currentDocument, $blockers->first());
        $stages = $this->stages(
            $currentKey,
            $linkedDocuments,
            $blockers,
            $this->usesFulfillmentStages($graph, $currentKey, $linkedDocuments)
        );

        return new ProcurementChainSummary(
            root: [
                'type' => $this->rootType($rootContext),
                'id' => $rootId,
                'label' => trans_message("procurement.chain.roots.{$this->rootType($rootContext)}"),
                'href' => "/procurement/chains/{$rootContext}/{$rootId}",
            ],
            currentStage: $currentStage,
            nextAction: $nextAction,
            blockers: $blockers,
            warnings: collect(),
            linkedDocuments: $linkedDocuments,
            stages: $stages,
            permissions: $this->actionResolver->permissions($actor, $organizationId),
        );
    }

    /**
     * @param array<string, mixed> $graph
     * @return array{0: string, 1: ProcurementChainAction|null, 2: Collection<int, ProcurementChainBlocker>}
     */
    private function resolveCurrentState(array $graph, ?User $actor, int $organizationId): array
    {
        $siteRequest = $graph['site_request'] ?? null;
        $purchaseRequest = $graph['purchase_request'] ?? null;
        $supplierRequests = $graph['supplier_requests'] ?? collect();
        $supplierProposals = $graph['supplier_proposals'] ?? collect();
        $proposalDecision = $graph['proposal_decision'] ?? null;
        $selectedProposal = $graph['selected_proposal'] ?? null;
        $purchaseOrder = $graph['purchase_order'] ?? null;
        $paymentDocuments = $graph['payment_documents'] ?? collect();
        $receipts = $graph['receipts'] ?? collect();
        $materialDeliveries = $graph['material_deliveries'] ?? collect();

        if ($siteRequest instanceof SiteRequest && in_array($siteRequest->status, [
            SiteRequestStatusEnum::REJECTED,
            SiteRequestStatusEnum::CANCELLED,
        ], true)) {
            return [$siteRequest->status === SiteRequestStatusEnum::REJECTED ? 'rejected' : 'cancelled', null, collect()];
        }

        if (! $purchaseRequest instanceof PurchaseRequest) {
            if ($siteRequest instanceof SiteRequest && $siteRequest->status === SiteRequestStatusEnum::APPROVED) {
                if ($siteRequest->request_type === SiteRequestTypeEnum::MATERIAL_REQUEST) {
                    $warehouseDelivery = $this->warehouseDelivery($materialDeliveries);

                    if ($warehouseDelivery instanceof ProjectMaterialDelivery) {
                        return $this->warehouseDeliveryState($warehouseDelivery, $actor, $organizationId);
                    }

                    if ($this->fulfillmentDecision($siteRequest) === null) {
                        return [
                            'fulfillment_source_required',
                            $this->action(
                                'determine_fulfillment_source',
                                '/site-requests/'.$siteRequest->id.'?fulfillment=1',
                                $actor,
                                $organizationId
                            ),
                            collect([$this->blocker('fulfillment_source_not_selected', 'site_request', $siteRequest->id)]),
                        ];
                    }
                }

                return [
                    'site_request_approved',
                    $this->action('create_purchase_request', '/procurement/purchase-requests/create?site_request_id='.$siteRequest->id, $actor, $organizationId),
                    collect(),
                ];
            }

            return [
                'site_request_created',
                $this->action('approve_site_request', $siteRequest ? '/site-requests/'.$siteRequest->id : null, $actor, $organizationId),
                collect([$this->blocker('site_request_not_approved', 'site_request', $siteRequest?->id)]),
            ];
        }

        if ($purchaseRequest->status === PurchaseRequestStatusEnum::REJECTED) {
            return ['rejected', null, collect()];
        }

        if ($purchaseRequest->status === PurchaseRequestStatusEnum::CANCELLED) {
            return ['cancelled', null, collect()];
        }

        if ($purchaseRequest->status !== PurchaseRequestStatusEnum::APPROVED) {
            return [
                'purchase_request_created',
                $this->action(
                    'approve_purchase_request',
                    "/api/v1/admin/procurement/purchase-requests/{$purchaseRequest->id}/approve",
                    $actor,
                    $organizationId,
                    'POST'
                ),
                collect([$this->blocker('purchase_request_not_approved', 'purchase_request', $purchaseRequest->id)]),
            ];
        }

        if (! $purchaseOrder instanceof PurchaseOrder) {
            $activeSupplierRequests = $supplierRequests
                ->reject(static fn (SupplierRequest $request): bool => in_array($request->status, [
                    SupplierRequestStatusEnum::CANCELLED,
                    SupplierRequestStatusEnum::EXPIRED,
                ], true))
                ->values();

            if ($activeSupplierRequests->isEmpty()) {
                return [
                    'purchase_request_approved',
                    $this->action('create_supplier_request', $this->supplierRequestsWorkspaceHref($purchaseRequest->id), $actor, $organizationId),
                    collect(),
                ];
            }

            $draftRequest = $activeSupplierRequests->first(
                static fn (SupplierRequest $request): bool => $request->status === SupplierRequestStatusEnum::DRAFT
            );

            if ($draftRequest instanceof SupplierRequest) {
                return [
                    'supplier_request_created',
                    $this->action(
                        'send_supplier_request',
                        "/api/v1/admin/procurement/supplier-requests/{$draftRequest->id}/send",
                        $actor,
                        $organizationId,
                        'POST'
                    ),
                    collect(),
                ];
            }

            if ($supplierProposals->isEmpty()) {
                return [
                    'supplier_request_sent',
                    $this->action(
                        'wait_for_proposals',
                        '/procurement/purchase-requests/'.$purchaseRequest->id,
                        $actor,
                        $organizationId,
                        'GET',
                        false,
                        trans_message('procurement.chain.actions.disabled.waiting_for_supplier')
                    ),
                    collect(),
                ];
            }

            if (! $proposalDecision instanceof SupplierProposalDecision) {
                return [
                    'commercial_proposal_received',
                    $this->action('select_proposal', "/procurement/proposals/compare?purchase_request_id={$purchaseRequest->id}", $actor, $organizationId),
                    collect(),
                ];
            }

            if ($proposalDecision->status === SupplierProposalDecisionEnum::APPROVAL_REQUIRED) {
                return [
                    'proposal_selected',
                    $this->action('approve_proposal_selection', '/procurement/approvals', $actor, $organizationId),
                    collect([$this->blocker('proposal_selection_requires_approval', 'supplier_proposal_decision', $proposalDecision->id)]),
                ];
            }

            $acceptHref = $selectedProposal instanceof SupplierProposal
                ? "/api/v1/admin/procurement/proposals/{$selectedProposal->id}/accept"
                : '/procurement/proposals';

            return [
                'proposal_selected',
                $this->action(
                    'accept_proposal',
                    $acceptHref,
                    $actor,
                    $organizationId,
                    $selectedProposal instanceof SupplierProposal ? 'POST' : 'GET'
                ),
                collect(),
            ];
        }

        if ($purchaseOrder->status === PurchaseOrderStatusEnum::CANCELLED) {
            return ['cancelled', null, collect()];
        }

        if (in_array($purchaseOrder->status, [PurchaseOrderStatusEnum::DRAFT, PurchaseOrderStatusEnum::SENT], true)) {
            return [
                'purchase_order_created',
                $this->action('open_purchase_order', '/procurement/purchase-orders/'.$purchaseOrder->id, $actor, $organizationId),
                collect(),
            ];
        }

        $paidAmount = $this->paidAmount($paymentDocuments);
        $requiredAmount = round((float) $purchaseOrder->total_amount, 2);
        $paymentDocument = $paymentDocuments->first();

        if ($paymentDocuments->isEmpty()) {
            return [
                'payment_document_missing',
                $this->action(
                    'create_or_open_payment_document',
                    "/api/v1/admin/procurement/purchase-orders/{$purchaseOrder->id}/payment-document",
                    $actor,
                    $organizationId,
                    'POST'
                ),
                collect([$this->blocker('payment_document_missing', 'purchase_order', $purchaseOrder->id)]),
            ];
        }

        if ($paymentDocument instanceof PaymentDocument && $paymentDocument->status === PaymentDocumentStatus::DRAFT) {
            return [
                'payment_document_draft',
                $this->action(
                    'submit_payment_document',
                    "/api/v1/admin/payments/documents/{$paymentDocument->id}/submit",
                    $actor,
                    $organizationId,
                    'POST'
                ),
                collect([$this->blocker('payment_document_not_submitted', 'payment_document', $paymentDocument->id)]),
            ];
        }

        if ($paymentDocument instanceof PaymentDocument && in_array($paymentDocument->status, [
            PaymentDocumentStatus::SUBMITTED,
            PaymentDocumentStatus::PENDING_APPROVAL,
        ], true)) {
            return [
                'payment_approval_required',
                $this->action(
                    'approve_payment_document',
                    "/api/v1/admin/payments/approvals/documents/{$paymentDocument->id}/approve",
                    $actor,
                    $organizationId,
                    'POST'
                ),
                collect([$this->blocker('payment_approval_required', 'payment_document', $paymentDocument->id)]),
            ];
        }

        if ($requiredAmount > 0.0001 && $paidAmount + 0.0001 < $requiredAmount) {
            $stageKey = $paidAmount <= 0.0001 ? 'payment_approved' : 'payment_partially_registered';
            $blockerKey = $paidAmount <= 0.0001 ? 'payment_registration_required' : 'payment_amount_not_enough';

            return [
                $stageKey,
                $this->action(
                    'register_payment',
                    $paymentDocument instanceof PaymentDocument
                        ? "/api/v1/admin/payments/documents/{$paymentDocument->id}/register-payment"
                        : '/payments/documents',
                    $actor,
                    $organizationId,
                    'POST'
                ),
                collect([$this->blocker($blockerKey, 'payment_document', $paymentDocument?->id)]),
            ];
        }

        if ($receipts->isEmpty()) {
            return [
                'payment_registered',
                $this->action('receive_materials', "/procurement/purchase-orders/{$purchaseOrder->id}?receive_materials=1", $actor, $organizationId),
                collect(),
            ];
        }

        $postedReceipt = $receipts->first(static fn (PurchaseReceipt $receipt): bool => (string) $receipt->status->value === 'posted');

        if ($postedReceipt instanceof PurchaseReceipt) {
            if ($purchaseOrder->status === PurchaseOrderStatusEnum::DELIVERED) {
                return ['completed', null, collect()];
            }

            return [
                'warehouse_posted',
                $this->action('open_warehouse_receipt', '/warehouse', $actor, $organizationId),
                collect(),
            ];
        }

        return [
            'receipt_created',
            $this->action('open_warehouse_receipt', '/warehouse', $actor, $organizationId),
            collect([$this->blocker('warehouse_posting_missing', 'purchase_order', $purchaseOrder->id)]),
        ];
    }

    private function action(
        string $key,
        ?string $href,
        ?User $actor,
        int $organizationId,
        string $method = 'GET',
        bool $domainEnabled = true,
        ?string $domainDisabledReason = null,
        string $scope = 'procurement_chain',
        int $priority = 100
    ): ProcurementChainAction {
        return $this->actionResolver->action(
            key: $key,
            href: $href,
            actor: $actor,
            organizationId: $organizationId,
            method: $method,
            domainEnabled: $domainEnabled,
            domainDisabledReason: $domainDisabledReason,
            scope: $scope,
            priority: $priority
        );
    }

    private function blocker(string $key, ?string $entityType = null, ?int $entityId = null): ProcurementChainBlocker
    {
        return new ProcurementChainBlocker(
            key: $key,
            message: trans_message("procurement.chain.blockers.{$key}"),
            severity: 'warning',
            entityType: $entityType,
            entityId: $entityId,
        );
    }

    /**
     * @param Collection<int, ProjectMaterialDelivery> $deliveries
     */
    private function warehouseDelivery(Collection $deliveries): ?ProjectMaterialDelivery
    {
        return $deliveries
            ->filter(static fn (ProjectMaterialDelivery $delivery): bool => $delivery->source_type === 'warehouse')
            ->reject(static fn (ProjectMaterialDelivery $delivery): bool => $delivery->status === ProjectMaterialDeliveryStatusEnum::CANCELLED)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * @return array{0: string, 1: ProcurementChainAction|null, 2: Collection<int, ProcurementChainBlocker>}
     */
    private function warehouseDeliveryState(
        ProjectMaterialDelivery $delivery,
        ?User $actor,
        int $organizationId
    ): array {
        if ($delivery->status === ProjectMaterialDeliveryStatusEnum::ACCEPTED) {
            return ['project_material_accepted', null, collect()];
        }

        $href = '/warehouse?tab=project-material-deliveries&delivery_id='.$delivery->id;

        if (in_array($delivery->status, [
            ProjectMaterialDeliveryStatusEnum::IN_TRANSIT,
            ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED,
            ProjectMaterialDeliveryStatusEnum::DELIVERED,
        ], true)) {
            return [
                'warehouse_in_transit',
                $this->action('open_project_material_delivery', $href, $actor, $organizationId),
                collect(),
            ];
        }

        return [
            'warehouse_reserved',
            $this->action('open_project_material_delivery', $href, $actor, $organizationId),
            collect(),
        ];
    }

    private function fulfillmentDecision(SiteRequest $siteRequest): ?array
    {
        $metadata = $siteRequest->metadata ?? [];
        $decision = $metadata['fulfillment_decision'] ?? null;

        return is_array($decision) ? $decision : null;
    }

    /**
     * @param Collection<int, PaymentDocument> $paymentDocuments
     */
    private function paidAmount(Collection $paymentDocuments): float
    {
        return (float) $paymentDocuments->sum(static function (PaymentDocument $document): float {
            $paidAmount = (float) $document->paid_amount;

            if ($document->status === PaymentDocumentStatus::PAID && $paidAmount <= 0.0001) {
                return (float) $document->amount;
            }

            return $paidAmount;
        });
    }

    /**
     * @param array<string, mixed> $graph
     * @return Collection<int, ProcurementChainDocumentLink>
     */
    private function linkedDocuments(array $graph): Collection
    {
        $documents = collect();

        if (($graph['site_request'] ?? null) instanceof SiteRequest) {
            $documents->push($this->siteRequestLink($graph['site_request']));
        }

        if (($graph['purchase_request'] ?? null) instanceof PurchaseRequest) {
            $documents->push($this->purchaseRequestLink($graph['purchase_request']));
        }

        foreach (($graph['supplier_requests'] ?? collect()) as $supplierRequest) {
            $documents->push($this->supplierRequestLink($supplierRequest));
        }

        foreach (($graph['supplier_proposals'] ?? collect()) as $supplierProposal) {
            $documents->push($this->supplierProposalLink($supplierProposal));
        }

        if (($graph['purchase_order'] ?? null) instanceof PurchaseOrder) {
            $documents->push($this->purchaseOrderLink($graph['purchase_order']));
        }

        foreach (($graph['material_deliveries'] ?? collect()) as $delivery) {
            $documents->push($this->projectMaterialDeliveryLink($delivery));
        }

        foreach (($graph['payment_documents'] ?? collect()) as $paymentDocument) {
            $documents->push($this->paymentDocumentLink($paymentDocument));
        }

        foreach (($graph['receipts'] ?? collect()) as $receipt) {
            $documents->push($this->receiptLink($receipt));
        }

        return $documents->values();
    }

    private function siteRequestLink(SiteRequest $siteRequest): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'site_request',
            id: $siteRequest->id,
            label: trans_message('procurement.chain.documents.site_request'),
            number: (string) $siteRequest->id,
            status: $siteRequest->status->value,
            statusLabel: $siteRequest->status->label(),
            href: '/site-requests/'.$siteRequest->id,
        );
    }

    private function purchaseRequestLink(PurchaseRequest $purchaseRequest): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'purchase_request',
            id: $purchaseRequest->id,
            label: trans_message('procurement.chain.documents.purchase_request'),
            number: $purchaseRequest->request_number,
            status: $purchaseRequest->status->value,
            statusLabel: $purchaseRequest->status->label(),
            href: '/procurement/purchase-requests/'.$purchaseRequest->id,
            amount: $purchaseRequest->budget_amount !== null ? (float) $purchaseRequest->budget_amount : null,
            currency: $purchaseRequest->budget_currency,
        );
    }

    private function supplierRequestLink(SupplierRequest $supplierRequest): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'supplier_request',
            id: $supplierRequest->id,
            label: trans_message('procurement.chain.documents.supplier_request'),
            number: $supplierRequest->request_number,
            status: $supplierRequest->status->value,
            statusLabel: $supplierRequest->status->label(),
            href: $this->supplierRequestsWorkspaceHref($supplierRequest->purchase_request_id),
            supplierName: $this->supplierName($supplierRequest->supplier_snapshot),
        );
    }

    private function supplierProposalLink(SupplierProposal $supplierProposal): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'supplier_proposal',
            id: $supplierProposal->id,
            label: trans_message('procurement.chain.documents.supplier_proposal'),
            number: $supplierProposal->proposal_number,
            status: $supplierProposal->status->value,
            statusLabel: $supplierProposal->status->label(),
            href: '/procurement/proposals/'.$supplierProposal->id,
            amount: (float) $supplierProposal->total_amount,
            currency: $supplierProposal->currency,
            supplierName: $this->supplierName($supplierProposal->supplier_snapshot),
        );
    }

    private function purchaseOrderLink(PurchaseOrder $purchaseOrder): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'purchase_order',
            id: $purchaseOrder->id,
            label: trans_message('procurement.chain.documents.purchase_order'),
            number: $purchaseOrder->order_number,
            status: $purchaseOrder->status->value,
            statusLabel: $purchaseOrder->status->label(),
            href: '/procurement/purchase-orders/'.$purchaseOrder->id,
            amount: (float) $purchaseOrder->total_amount,
            currency: $purchaseOrder->currency,
            supplierName: $this->supplierName($purchaseOrder->supplier_snapshot),
        );
    }

    private function paymentDocumentLink(PaymentDocument $document): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'payment_document',
            id: $document->id,
            label: trans_message('procurement.chain.documents.payment_document'),
            number: $document->document_number,
            status: $document->status->value,
            statusLabel: $document->status->label(),
            href: '/payments/documents/'.$document->id,
            amount: (float) $document->amount,
            currency: $document->currency,
        );
    }

    private function receiptLink(PurchaseReceipt $receipt): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'purchase_receipt',
            id: $receipt->id,
            label: trans_message('procurement.chain.documents.purchase_receipt'),
            number: $receipt->receipt_number,
            status: $receipt->status->value,
            statusLabel: $receipt->status->label(),
            href: '/warehouse',
        );
    }

    private function projectMaterialDeliveryLink(ProjectMaterialDelivery $delivery): ProcurementChainDocumentLink
    {
        return new ProcurementChainDocumentLink(
            type: 'project_material_delivery',
            id: $delivery->id,
            label: trans_message('procurement.chain.documents.project_material_delivery'),
            number: (string) $delivery->id,
            status: $delivery->status->value,
            statusLabel: $delivery->status->label(),
            href: '/warehouse?tab=project-material-deliveries&delivery_id='.$delivery->id,
        );
    }

    /**
     * @param Collection<int, ProcurementChainDocumentLink> $documents
     */
    private function documentForStage(string $stage, Collection $documents): ?ProcurementChainDocumentLink
    {
        $type = match ($stage) {
            'site_request_created', 'site_request_approved', 'fulfillment_source_required' => 'site_request',
            'warehouse_reserved', 'warehouse_in_transit', 'project_material_accepted' => 'project_material_delivery',
            'purchase_request_created', 'purchase_request_approved' => 'purchase_request',
            'supplier_request_created', 'supplier_request_sent' => 'supplier_request',
            'commercial_proposal_received', 'proposal_selected' => 'supplier_proposal',
            'purchase_order_created', 'payment_document_missing', 'payment_registered' => 'purchase_order',
            'payment_document_draft',
            'payment_approval_required',
            'payment_approved',
            'payment_partially_registered' => 'payment_document',
            'receipt_created', 'warehouse_posted', 'completed' => 'purchase_receipt',
            default => null,
        };

        if ($type === null) {
            return null;
        }

        return $documents->where('type', $type)->last();
    }

    /**
     * @param Collection<int, ProcurementChainDocumentLink> $documents
     * @param Collection<int, ProcurementChainBlocker> $blockers
     * @return Collection<int, ProcurementChainStage>
     */
    private function stages(
        string $currentKey,
        Collection $documents,
        Collection $blockers,
        bool $includeFulfillmentStages
    ): Collection
    {
        $stageOrder = $this->stageOrder($documents, $includeFulfillmentStages);
        $currentIndex = array_search($currentKey, $stageOrder, true);
        $currentIndex = $currentIndex === false ? count($stageOrder) - 1 : $currentIndex;

        return collect($stageOrder)
            ->map(function (string $stageKey, int $index) use ($currentKey, $currentIndex, $documents, $blockers): ProcurementChainStage {
                $status = 'pending';

                if ($index < $currentIndex) {
                    $status = 'done';
                } elseif ($stageKey === $currentKey) {
                    $status = $blockers->isEmpty() ? 'current' : 'blocked';
                }

                if ($currentKey === 'completed' && $stageKey === 'completed') {
                    $status = 'done';
                }

                return $this->stage(
                    $stageKey,
                    $status,
                    $this->documentForStage($stageKey, $documents),
                    $stageKey === $currentKey ? $blockers->first() : null
                );
            })
            ->values();
    }

    /**
     * @param array<string, mixed> $graph
     * @param Collection<int, ProcurementChainDocumentLink> $documents
     */
    private function usesFulfillmentStages(array $graph, string $currentKey, Collection $documents): bool
    {
        $siteRequest = $graph['site_request'] ?? null;

        if (!$siteRequest instanceof SiteRequest || $siteRequest->request_type !== SiteRequestTypeEnum::MATERIAL_REQUEST) {
            return false;
        }

        return $currentKey === 'fulfillment_source_required'
            || $this->fulfillmentDecision($siteRequest) !== null
            || $documents->contains(static fn (ProcurementChainDocumentLink $document): bool => $document->type === 'project_material_delivery');
    }

    /**
     * @param Collection<int, ProcurementChainDocumentLink> $documents
     * @return array<int, string>
     */
    private function stageOrder(Collection $documents, bool $includeFulfillmentStages): array
    {
        $warehouseStages = ['warehouse_reserved', 'warehouse_in_transit', 'project_material_accepted'];
        $fulfillmentStages = array_merge(['fulfillment_source_required'], $warehouseStages);
        $hasWarehouseDelivery = $documents->contains(
            static fn (ProcurementChainDocumentLink $document): bool => $document->type === 'project_material_delivery'
        );

        return array_values(array_filter(
            self::STAGE_ORDER,
            static function (string $stage) use ($includeFulfillmentStages, $fulfillmentStages, $warehouseStages, $hasWarehouseDelivery): bool {
                if (!$includeFulfillmentStages && in_array($stage, $fulfillmentStages, true)) {
                    return false;
                }

                if ($includeFulfillmentStages && !$hasWarehouseDelivery && in_array($stage, $warehouseStages, true)) {
                    return false;
                }

                return true;
            }
        ));
    }

    private function stage(
        string $key,
        string $status,
        ?ProcurementChainDocumentLink $document = null,
        ?ProcurementChainBlocker $blocker = null
    ): ProcurementChainStage {
        return new ProcurementChainStage(
            key: $key,
            label: trans_message("procurement.chain.stages.{$key}"),
            status: $status,
            description: trans_message("procurement.chain.stage_descriptions.{$key}"),
            document: $document,
            blocker: $blocker,
            severity: $blocker instanceof ProcurementChainBlocker ? 'warning' : 'neutral',
        );
    }

    /**
     * @param array<string, mixed> $graph
     */
    private function organizationId(array $graph): int
    {
        foreach (['site_request', 'purchase_request', 'purchase_order'] as $key) {
            $model = $graph[$key] ?? null;
            if ($model !== null && isset($model->organization_id)) {
                return (int) $model->organization_id;
            }
        }

        $document = ($graph['payment_documents'] ?? collect())->first();

        return $document instanceof PaymentDocument ? (int) $document->organization_id : 0;
    }

    private function rootType(string $context): string
    {
        return match ($context) {
            'site-requests' => 'site_request',
            'purchase-requests' => 'purchase_request',
            'purchase-orders' => 'purchase_order',
            'payment-documents' => 'payment_document',
            'purchase-receipts' => 'purchase_receipt',
            default => $context,
        };
    }

    private function supplierRequestsWorkspaceHref(int $purchaseRequestId): string
    {
        return "/procurement?tab=supplier_requests&purchase_request_id={$purchaseRequestId}";
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    private function supplierName(?array $snapshot): ?string
    {
        $name = $snapshot['display_name'] ?? $snapshot['name'] ?? null;

        return is_string($name) && trim($name) !== '' ? trim($name) : null;
    }
}
