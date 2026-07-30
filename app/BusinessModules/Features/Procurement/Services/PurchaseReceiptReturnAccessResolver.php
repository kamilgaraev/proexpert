<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Contracts\PurchaseReceiptReturnAuthorizer;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;

use function trans_message;

final readonly class PurchaseReceiptReturnAccessResolver implements PurchaseReceiptReturnAuthorizer
{
    public function __construct(private AuthorizationService $authorization) {}

    public function canReturn(
        User $actor,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): bool {
        $line = $this->resolve($organizationId, $purchaseOrderId, $receiptLineId);
        if (! $line instanceof PurchaseReceiptLine) {
            return false;
        }

        return $this->canAccessResolved(
            $actor,
            $line,
            $organizationId,
            $purchaseOrderId,
            $receiptLineId,
        );
    }

    public function assertCanReturn(
        User $actor,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): PurchaseReceiptLine {
        $line = $this->resolve($organizationId, $purchaseOrderId, $receiptLineId);
        if (! $line instanceof PurchaseReceiptLine || ! $this->canAccessResolved(
            $actor,
            $line,
            $organizationId,
            $purchaseOrderId,
            $receiptLineId,
        )) {
            throw new DomainException(
                trans_message('procurement.purchase_orders.receipt_return_forbidden'),
            );
        }

        return $line;
    }

    private function canAccessResolved(
        User $actor,
        PurchaseReceiptLine $line,
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): bool {
        $projectId = (int) $line->purchaseReceipt->purchaseOrder
            ->purchaseRequest->siteRequest->project_id;

        return $projectId > 0
            && $this->authorization->can($actor, 'procurement.purchase_orders.receive', [
                'context_type' => 'project',
                'organization_id' => $organizationId,
                'project_id' => $projectId,
                'purchase_order_id' => $purchaseOrderId,
                'purchase_receipt_line_id' => $receiptLineId,
                'resource_type' => 'purchase_receipt_line',
                'resource_id' => $receiptLineId,
                'actor_id' => (int) $actor->getAuthIdentifier(),
            ]);
    }

    private function resolve(
        int $organizationId,
        int $purchaseOrderId,
        int $receiptLineId,
    ): ?PurchaseReceiptLine {
        if ($organizationId < 1 || $purchaseOrderId < 1 || $receiptLineId < 1) {
            return null;
        }

        return PurchaseReceiptLine::query()
            ->with([
                'purchaseReceipt.purchaseOrder.purchaseRequest.siteRequest',
                'purchaseOrderItem',
                'inventoryLot',
            ])
            ->whereKey($receiptLineId)
            ->whereHas('purchaseReceipt', static fn ($receipt) => $receipt
                ->where('organization_id', $organizationId)
                ->where('purchase_order_id', $purchaseOrderId)
                ->whereHas('purchaseOrder', static fn ($order) => $order
                    ->where('organization_id', $organizationId)
                    ->whereHas('purchaseRequest', static fn ($request) => $request
                        ->where('organization_id', $organizationId)
                        ->whereHas('siteRequest', static fn ($siteRequest) => $siteRequest
                            ->where('organization_id', $organizationId)
                            ->whereNotNull('project_id')))))
            ->whereHas('purchaseOrderItem', static fn ($item) => $item
                ->where('purchase_order_id', $purchaseOrderId))
            ->first();
    }
}
