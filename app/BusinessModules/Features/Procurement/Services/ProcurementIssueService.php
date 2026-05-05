<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use Illuminate\Support\Collection;

use function trans_message;

final class ProcurementIssueService
{
    public function __construct(
        private readonly ProcurementLifecycleService $lifecycleService
    ) {
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, meta: array<string, int>, summary: array<string, int>}
     */
    public function paginate(int $organizationId, ?string $scope, int $page, int $perPage): array
    {
        $normalizedScope = in_array($scope, ['purchase_requests', 'purchase_orders'], true) ? $scope : 'all';
        $issues = collect();

        if ($normalizedScope === 'all' || $normalizedScope === 'purchase_requests') {
            $issues = $issues->merge($this->purchaseRequestIssues($organizationId));
        }

        if ($normalizedScope === 'all' || $normalizedScope === 'purchase_orders') {
            $issues = $issues->merge($this->purchaseOrderIssues($organizationId));
        }

        $sorted = $issues
            ->sortBy([
                ['severity_rank', 'asc'],
                ['created_timestamp', 'desc'],
                ['entity_number', 'asc'],
            ])
            ->values();

        $total = $sorted->count();
        $items = $sorted
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(static function (array $issue): array {
                unset($issue['severity_rank'], $issue['created_timestamp']);

                return $issue;
            })
            ->values()
            ->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => max((int) ceil($total / $perPage), 1),
            ],
            'summary' => $this->summary($sorted),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseRequestIssues(int $organizationId): Collection
    {
        return PurchaseRequest::forOrganization($organizationId)
            ->with(['siteRequest', 'assignedUser', 'purchaseOrders.items', 'supplierRequests.proposals', 'supplierRequests.proposalDecision'])
            ->whereIn('status', [
                PurchaseRequestStatusEnum::PENDING->value,
                PurchaseRequestStatusEnum::APPROVED->value,
            ])
            ->orderByDesc('created_at')
            ->get()
            ->flatMap(function (PurchaseRequest $purchaseRequest): array {
                $issues = [];

                if ($purchaseRequest->status === PurchaseRequestStatusEnum::PENDING) {
                    $issues[] = $this->makeIssue(
                        id: "pr-pending-{$purchaseRequest->id}",
                        scope: 'purchase_requests',
                        severity: 'warning',
                        type: 'purchase_request_pending',
                        title: trans_message('procurement.issues.types.purchase_request_pending.title'),
                        description: trans_message('procurement.issues.types.purchase_request_pending.description'),
                        nextAction: trans_message('procurement.issues.types.purchase_request_pending.next_action'),
                        entityNumber: $purchaseRequest->request_number,
                        entityHref: "/procurement/purchase-requests/{$purchaseRequest->id}",
                        meta: [
                            $this->purchaseRequestStatusLabel($purchaseRequest->status),
                            $purchaseRequest->siteRequest?->title ?? trans_message('procurement.issues.meta.site_request_missing'),
                            $purchaseRequest->created_at?->toIso8601String(),
                        ],
                        createdAt: $purchaseRequest->created_at?->toIso8601String(),
                        createdTimestamp: $purchaseRequest->created_at?->getTimestamp() ?? 0,
                    );
                }

                if (
                    $purchaseRequest->status === PurchaseRequestStatusEnum::APPROVED
                    && $this->lifecycleService->forPurchaseRequest($purchaseRequest)->canCreateSupplierRequest
                ) {
                    $lifecycleSummary = $this->lifecycleService->forPurchaseRequest($purchaseRequest);

                    $issues[] = $this->makeIssue(
                        id: "pr-without-order-{$purchaseRequest->id}",
                        scope: 'purchase_requests',
                        severity: 'warning',
                        type: 'purchase_request_without_order',
                        title: trans_message('procurement.issues.types.purchase_request_without_order.title'),
                        description: trans_message('procurement.issues.types.purchase_request_without_order.description'),
                        nextAction: $lifecycleSummary->nextActionLabel
                            ?? trans_message('procurement.issues.types.purchase_request_without_order.next_action'),
                        entityNumber: $purchaseRequest->request_number,
                        entityHref: "/procurement/purchase-requests/{$purchaseRequest->id}",
                        meta: [
                            $this->purchaseRequestStatusLabel($purchaseRequest->status),
                            $purchaseRequest->siteRequest?->title ?? trans_message('procurement.issues.meta.site_request_missing'),
                            $purchaseRequest->assignedUser?->name ?? trans_message('procurement.issues.meta.assignee_missing'),
                        ],
                        createdAt: $purchaseRequest->created_at?->toIso8601String(),
                        createdTimestamp: $purchaseRequest->created_at?->getTimestamp() ?? 0,
                        actionHref: "/procurement/supplier-requests?purchase_request_id={$purchaseRequest->id}",
                        actionLabel: trans_message('procurement.issues.actions.create_supplier_request'),
                    );
                }

                return $issues;
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseOrderIssues(int $organizationId): Collection
    {
        return PurchaseOrder::forOrganization($organizationId)
            ->with(['supplier', 'externalSupplierContact', 'items'])
            ->whereIn('status', [
                PurchaseOrderStatusEnum::DRAFT->value,
                PurchaseOrderStatusEnum::SENT->value,
                PurchaseOrderStatusEnum::CONFIRMED->value,
                PurchaseOrderStatusEnum::IN_DELIVERY->value,
            ])
            ->orderByDesc('created_at')
            ->get()
            ->flatMap(function (PurchaseOrder $purchaseOrder): array {
                $issues = [];

                if ($purchaseOrder->status === PurchaseOrderStatusEnum::DRAFT) {
                    $issues[] = $this->purchaseOrderIssue(
                        $purchaseOrder,
                        'purchase_order_draft',
                        'warning',
                        'draft',
                    );
                }

                if ($purchaseOrder->status === PurchaseOrderStatusEnum::SENT) {
                    $issues[] = $this->purchaseOrderIssue(
                        $purchaseOrder,
                        'purchase_order_sent',
                        'info',
                        'sent',
                    );
                }

                if ($purchaseOrder->status === PurchaseOrderStatusEnum::CONFIRMED && !$purchaseOrder->hasContract()) {
                    $issues[] = $this->purchaseOrderIssue(
                        $purchaseOrder,
                        'purchase_order_confirmed_without_contract',
                        'warning',
                        'confirmed_without_contract',
                    );
                }

                if ($purchaseOrder->status === PurchaseOrderStatusEnum::CONFIRMED) {
                    $issues[] = $this->purchaseOrderIssue(
                        $purchaseOrder,
                        'purchase_order_confirmed_waiting_delivery',
                        'info',
                        'confirmed_waiting_delivery',
                    );
                }

                if ($purchaseOrder->status === PurchaseOrderStatusEnum::IN_DELIVERY) {
                    $issues[] = $this->purchaseOrderIssue(
                        $purchaseOrder,
                        'purchase_order_in_delivery',
                        'warning',
                        'in_delivery',
                    );
                }

                return $issues;
            });
    }

    private function purchaseOrderIssue(
        PurchaseOrder $purchaseOrder,
        string $type,
        string $severity,
        string $translationKey
    ): array {
        $lifecycleSummary = $this->lifecycleService->forPurchaseOrder($purchaseOrder);

        return $this->makeIssue(
            id: "po-{$translationKey}-{$purchaseOrder->id}",
            scope: 'purchase_orders',
            severity: $severity,
            type: $type,
            title: trans_message("procurement.issues.types.{$type}.title"),
            description: trans_message("procurement.issues.types.{$type}.description"),
            nextAction: $lifecycleSummary->nextActionLabel
                ?? trans_message("procurement.issues.types.{$type}.next_action"),
            entityNumber: $purchaseOrder->order_number,
            entityHref: "/procurement/purchase-orders/{$purchaseOrder->id}",
            meta: [
                $this->purchaseOrderStatusLabel($purchaseOrder->status),
                $this->supplierName($purchaseOrder),
                $purchaseOrder->delivery_date?->toDateString() ?? trans_message('procurement.issues.meta.delivery_date_missing'),
            ],
            createdAt: $purchaseOrder->created_at?->toIso8601String(),
            createdTimestamp: $purchaseOrder->created_at?->getTimestamp() ?? 0,
        );
    }

    /**
     * @param array<int, string|null> $meta
     * @return array<string, mixed>
     */
    private function makeIssue(
        string $id,
        string $scope,
        string $severity,
        string $type,
        string $title,
        string $description,
        string $nextAction,
        string $entityNumber,
        string $entityHref,
        array $meta,
        ?string $createdAt,
        int $createdTimestamp,
        ?string $actionHref = null,
        ?string $actionLabel = null,
    ): array {
        return [
            'id' => $id,
            'scope' => $scope,
            'severity' => $severity,
            'severity_rank' => $severity === 'warning' ? 0 : 1,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'next_action' => $nextAction,
            'entity_number' => $entityNumber,
            'entity_href' => $entityHref,
            'action_href' => $actionHref,
            'action_label' => $actionLabel,
            'meta' => array_values(array_filter($meta, static fn (?string $value): bool => $value !== null && $value !== '')),
            'created_at' => $createdAt,
            'created_timestamp' => $createdTimestamp,
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $issues
     * @return array<string, int>
     */
    private function summary(Collection $issues): array
    {
        return [
            'total' => $issues->count(),
            'approved_without_order' => $issues->where('type', 'purchase_request_without_order')->count(),
            'waiting_send' => $issues->where('type', 'purchase_order_draft')->count(),
            'waiting_confirmation' => $issues->where('type', 'purchase_order_sent')->count(),
            'waiting_receipt' => $issues->where('type', 'purchase_order_in_delivery')->count(),
        ];
    }

    private function purchaseRequestStatusLabel(PurchaseRequestStatusEnum $status): string
    {
        return trans_message("procurement.issues.status.purchase_requests.{$status->value}");
    }

    private function purchaseOrderStatusLabel(PurchaseOrderStatusEnum $status): string
    {
        return trans_message("procurement.issues.status.purchase_orders.{$status->value}");
    }

    private function supplierName(PurchaseOrder $purchaseOrder): string
    {
        return $purchaseOrder->supplier?->name
            ?? $purchaseOrder->externalSupplierContact?->name
            ?? trans_message('procurement.issues.meta.supplier_missing');
    }
}
