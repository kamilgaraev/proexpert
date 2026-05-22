<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\Procurement\Enums\ProcurementApprovalStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\ProcurementAuditEventTypeEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\ProcurementApproval;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class MobileProcurementService
{
    public const REQUEST_STATUSES = [
        'draft',
        'pending',
        'approved',
        'rejected',
        'cancelled',
    ];

    public const ORDER_STATUSES = [
        'draft',
        'sent',
        'confirmed',
        'in_delivery',
        'partially_delivered',
        'delivered',
        'cancelled',
    ];

    public const APPROVAL_STATUSES = [
        'pending',
        'approved',
        'rejected',
        'cancelled',
    ];

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly ProcurementApprovalService $approvalService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly ProcurementAuditService $auditService
    ) {
    }

    public function summary(int $organizationId, array $filters, User $user): array
    {
        $requestQuery = $this->purchaseRequestQuery($organizationId, $filters);
        $orderQuery = $this->purchaseOrderQuery($organizationId, $filters);
        $approvalQuery = $this->approvalQuery($organizationId, ['status' => ProcurementApprovalStatusEnum::PENDING->value]);

        $orders = (clone $orderQuery)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
        $requests = (clone $requestQuery)
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();
        $assignedApprovals = $this->decorateApprovals(
            (clone $approvalQuery)->orderByDesc('requested_at')->limit(20)->get(),
            $user,
            $organizationId
        )->filter(static fn (ProcurementApproval $approval): bool => (bool) $approval->getAttribute('can_resolve'))
            ->values();

        return [
            'summary' => [
                'purchase_requests_count' => (int) (clone $requestQuery)->count(),
                'pending_requests_count' => (int) (clone $requestQuery)->where('status', PurchaseRequestStatusEnum::PENDING->value)->count(),
                'purchase_orders_count' => (int) (clone $orderQuery)->count(),
                'receivable_orders_count' => (int) (clone $orderQuery)->whereIn('status', [
                    PurchaseOrderStatusEnum::CONFIRMED->value,
                    PurchaseOrderStatusEnum::IN_DELIVERY->value,
                    PurchaseOrderStatusEnum::PARTIALLY_DELIVERED->value,
                ])->count(),
                'pending_approvals_count' => $assignedApprovals->count(),
            ],
            'purchase_requests' => $requests,
            'purchase_orders' => $orders,
            'assigned_approvals' => $assignedApprovals,
            'warehouses' => $this->activeWarehouses($organizationId),
        ];
    }

    public function paginatePurchaseRequests(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->purchaseRequestQuery($organizationId, $filters)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findPurchaseRequest(int $organizationId, int $purchaseRequestId): PurchaseRequest
    {
        $purchaseRequest = $this->purchaseRequestQuery($organizationId, [])
            ->whereKey($purchaseRequestId)
            ->first();

        if (!$purchaseRequest instanceof PurchaseRequest) {
            throw new DomainException(trans_message('procurement.mobile.errors.purchase_request_not_found'));
        }

        return $purchaseRequest;
    }

    public function paginatePurchaseOrders(int $organizationId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->purchaseOrderQuery($organizationId, $filters)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findPurchaseOrder(int $organizationId, int $purchaseOrderId): PurchaseOrder
    {
        $purchaseOrder = $this->purchaseOrderQuery($organizationId, [])
            ->whereKey($purchaseOrderId)
            ->first();

        if (!$purchaseOrder instanceof PurchaseOrder) {
            throw new DomainException(trans_message('procurement.mobile.errors.purchase_order_not_found'));
        }

        return $purchaseOrder;
    }

    public function paginateApprovals(int $organizationId, array $filters, User $user, int $perPage): LengthAwarePaginator
    {
        $paginator = $this->approvalQuery($organizationId, $filters)
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('requested_at')
            ->paginate($perPage);

        $paginator->setCollection($this->decorateApprovals($paginator->getCollection(), $user, $organizationId));

        return $paginator;
    }

    public function approveApproval(int $organizationId, int $approvalId, int $actorId, ?string $comment): ProcurementApproval
    {
        $approval = $this->findApproval($organizationId, $approvalId);

        return $this->approvalService->approve($approval, $actorId, $comment);
    }

    public function rejectApproval(int $organizationId, int $approvalId, int $actorId, string $comment): ProcurementApproval
    {
        $approval = $this->findApproval($organizationId, $approvalId);

        return $this->approvalService->reject($approval, $actorId, $comment);
    }

    public function receiveMaterials(
        int $organizationId,
        int $purchaseOrderId,
        int $warehouseId,
        array $items,
        int $userId,
        array $receiptData
    ): PurchaseOrder {
        $order = $this->findPurchaseOrder($organizationId, $purchaseOrderId);

        return $this->purchaseOrderService->receiveMaterials($order, $warehouseId, $items, $userId, $receiptData);
    }

    public function addOrderComment(int $organizationId, int $purchaseOrderId, int $userId, string $comment): PurchaseOrder
    {
        $order = $this->findPurchaseOrder($organizationId, $purchaseOrderId);

        $this->auditService->record(
            ProcurementAuditEventTypeEnum::PURCHASE_ORDER_COMMENTED->value,
            $order,
            $organizationId,
            $userId,
            $order->supplier_party_id,
            ['comment' => $comment]
        );

        return $this->findPurchaseOrder($organizationId, $purchaseOrderId);
    }

    public function activeWarehouses(int $organizationId): array
    {
        return OrganizationWarehouse::query()
            ->where('organization_id', $organizationId)
            ->active()
            ->orderByDesc('is_main')
            ->orderBy('name')
            ->get()
            ->map(static fn (OrganizationWarehouse $warehouse): array => [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'code' => $warehouse->code,
                'address' => $warehouse->address,
                'warehouse_type' => $warehouse->warehouse_type,
                'is_main' => (bool) $warehouse->is_main,
            ])
            ->values()
            ->all();
    }

    private function purchaseRequestQuery(int $organizationId, array $filters): Builder
    {
        return PurchaseRequest::query()
            ->forOrganization($organizationId)
            ->with([
                'siteRequest.project',
                'assignedUser',
                'lines',
                'purchaseOrders',
            ])
            ->withCount(['lines', 'supplierRequests', 'purchaseOrders'])
            ->when(isset($filters['status']), static function (Builder $query) use ($filters): void {
                $query->where('status', (string) $filters['status']);
            })
            ->when(isset($filters['project_id']), static function (Builder $query) use ($filters): void {
                $query->whereHas('siteRequest', static function (Builder $siteRequestQuery) use ($filters): void {
                    $siteRequestQuery->where('project_id', (int) $filters['project_id']);
                });
            })
            ->when(isset($filters['q']), static function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['q']);
                if ($search === '') {
                    return;
                }

                $query->where(static function (Builder $nestedQuery) use ($search): void {
                    $nestedQuery->where('request_number', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('siteRequest', static function (Builder $siteRequestQuery) use ($search): void {
                            $siteRequestQuery->where('title', 'like', "%{$search}%");
                        });
                });
            });
    }

    private function purchaseOrderQuery(int $organizationId, array $filters): Builder
    {
        return PurchaseOrder::query()
            ->forOrganization($organizationId)
            ->with([
                'supplier',
                'externalSupplierContact',
                'supplierParty',
                'purchaseRequest.siteRequest.project',
                'items.receiptLines',
                'receipts.warehouse',
                'receipts.receivedByUser',
                'receipts.lines',
                'auditEvents.actor',
            ])
            ->withCount(['items', 'receipts'])
            ->when(isset($filters['status']), static function (Builder $query) use ($filters): void {
                $query->where('status', (string) $filters['status']);
            })
            ->when(isset($filters['project_id']), static function (Builder $query) use ($filters): void {
                $query->whereHas('purchaseRequest.siteRequest', static function (Builder $siteRequestQuery) use ($filters): void {
                    $siteRequestQuery->where('project_id', (int) $filters['project_id']);
                });
            })
            ->when(isset($filters['q']), static function (Builder $query) use ($filters): void {
                $search = trim((string) $filters['q']);
                if ($search === '') {
                    return;
                }

                $query->where(static function (Builder $nestedQuery) use ($search): void {
                    $nestedQuery->where('order_number', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhereHas('supplier', static function (Builder $supplierQuery) use ($search): void {
                            $supplierQuery->where('name', 'like', "%{$search}%");
                        });
                });
            });
    }

    private function approvalQuery(int $organizationId, array $filters): Builder
    {
        return ProcurementApproval::query()
            ->forOrganization($organizationId)
            ->with([
                'approvable.winningProposal',
                'approvable.cheapestProposal',
                'requestedBy',
                'approvedBy',
                'rejectedBy',
            ])
            ->when(isset($filters['status']), static function (Builder $query) use ($filters): void {
                $query->where('status', (string) $filters['status']);
            });
    }

    private function findApproval(int $organizationId, int $approvalId): ProcurementApproval
    {
        $approval = $this->approvalQuery($organizationId, [])
            ->whereKey($approvalId)
            ->first();

        if (!$approval instanceof ProcurementApproval) {
            throw new DomainException(trans_message('procurement.mobile.errors.approval_not_found'));
        }

        return $approval;
    }

    private function decorateApprovals(EloquentCollection|Collection $approvals, User $user, int $organizationId): EloquentCollection|Collection
    {
        $canResolve = $this->authorizationService->can(
            $user,
            'procurement.approvals.resolve',
            ['organization_id' => $organizationId]
        );

        return $approvals->map(function (ProcurementApproval $approval) use ($user, $canResolve): ProcurementApproval {
            $blockers = $this->approvalService->resolutionBlockers($approval, (int) $user->id);
            $approval->setAttribute('resolution_blockers', $canResolve ? $blockers : [
                [
                    'code' => 'permission',
                    'message' => trans_message('procurement.mobile.errors.permission_denied'),
                ],
                ...$blockers,
            ]);
            $approval->setAttribute('can_resolve', $canResolve && $blockers === []);

            return $approval;
        });
    }
}
