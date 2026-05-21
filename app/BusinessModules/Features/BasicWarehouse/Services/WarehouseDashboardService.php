<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Features\BasicWarehouse\Enums\ProjectMaterialDeliveryStatusEnum;
use App\BusinessModules\Features\BasicWarehouse\Models\AssetReservation;
use App\BusinessModules\Features\BasicWarehouse\Models\InventoryAct;
use App\BusinessModules\Features\BasicWarehouse\Models\ProjectMaterialDelivery;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseTask;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseZone;
use App\BusinessModules\Features\Procurement\Enums\PurchaseOrderStatusEnum;
use App\BusinessModules\Features\Procurement\Enums\PurchaseRequestStatusEnum;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseRequest;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestPriorityEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use Illuminate\Support\Collection;

final class WarehouseDashboardService
{
    public function __construct(
        private readonly WarehouseService $warehouseService
    ) {
    }

    public function build(int $organizationId, int $warehouseId): array
    {
        /** @var Collection<int, array<string, mixed>> $stock */
        $stock = collect($this->warehouseService->getStockData($organizationId, ['warehouse_id' => $warehouseId]));
        $movements = collect($this->warehouseService->getMovementsData($organizationId, ['warehouse_id' => $warehouseId]));
        $now = now();

        $activeTaskStatuses = [
            WarehouseTask::STATUS_QUEUED,
            WarehouseTask::STATUS_IN_PROGRESS,
            WarehouseTask::STATUS_BLOCKED,
        ];
        $activeDeliveryStatuses = [
            ProjectMaterialDeliveryStatusEnum::REQUESTED->value,
            ProjectMaterialDeliveryStatusEnum::PROCESSING->value,
            ProjectMaterialDeliveryStatusEnum::RESERVED->value,
            ProjectMaterialDeliveryStatusEnum::PREPARING->value,
            ProjectMaterialDeliveryStatusEnum::IN_TRANSIT->value,
            ProjectMaterialDeliveryStatusEnum::PARTIALLY_DELIVERED->value,
            ProjectMaterialDeliveryStatusEnum::DELIVERED->value,
            ProjectMaterialDeliveryStatusEnum::PROBLEM->value,
        ];

        $lowStock = $stock->filter(static fn (array $item): bool => (bool) ($item['is_low_stock'] ?? false))->values();
        $topMaterials = $lowStock
            ->sortBy(static fn (array $item): float => (float) ($item['available_quantity'] ?? 0))
            ->take(5)
            ->values();

        if ($topMaterials->count() < 5) {
            $selectedMaterialIds = $topMaterials->pluck('material_id')->all();
            $topMaterials = $topMaterials
                ->concat(
                    $stock
                        ->reject(static fn (array $item): bool => in_array($item['material_id'] ?? null, $selectedMaterialIds, true))
                        ->sortByDesc(static fn (array $item): float => (float) ($item['total_value'] ?? 0))
                        ->take(5 - $topMaterials->count())
                        ->values()
                )
                ->values();
        }

        $activeTasksCount = WarehouseTask::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', $activeTaskStatuses)
            ->count();
        $blockedTasksCount = WarehouseTask::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', WarehouseTask::STATUS_BLOCKED)
            ->count();

        $activeReservationsQuery = AssetReservation::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', AssetReservation::STATUS_ACTIVE)
            ->where(static function ($query) use ($now): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', $now);
            });
        $activeReservationsCount = (clone $activeReservationsQuery)->count();
        $expiringReservationsCount = (clone $activeReservationsQuery)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now->copy()->addHours(12))
            ->count();

        $activeInventoriesCount = InventoryAct::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', [InventoryAct::STATUS_IN_PROGRESS, InventoryAct::STATUS_COMPLETED])
            ->count();
        $zonesCount = WarehouseZone::query()
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->count();

        $requests = $this->buildMaterialRequests($organizationId);
        $procurement = $this->buildProcurement($organizationId);
        $activeDeliveriesCount = ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->whereIn('status', $activeDeliveryStatuses)
            ->count();
        $problemDeliveriesCount = ProjectMaterialDelivery::query()
            ->where('organization_id', $organizationId)
            ->where('warehouse_id', $warehouseId)
            ->where('status', ProjectMaterialDeliveryStatusEnum::PROBLEM->value)
            ->count();

        return [
            'stats' => [
                'total_positions' => (float) $stock->sum(static fn (array $item): float => (float) ($item['total_quantity'] ?? 0)),
                'total_value' => (float) $stock->sum(static fn (array $item): float => (float) ($item['total_value'] ?? 0)),
                'unique_items' => $stock->count(),
                'low_stock_items' => $lowStock->count(),
                'reserved_quantity' => (float) $stock->sum(static fn (array $item): float => (float) ($item['reserved_quantity'] ?? 0)),
                'active_tasks' => $activeTasksCount,
                'active_reservations' => $activeReservationsCount,
                'active_inventories' => $activeInventoriesCount,
                'zones_count' => $zonesCount,
            ],
            'materials' => $topMaterials
                ->map(static function (array $item): array {
                    $photos = $item['photo_gallery'] ?? $item['asset_photo_gallery'] ?? [];
                    $photo = is_array($photos) && isset($photos[0]) && is_array($photos[0]) ? $photos[0] : null;

                    return [
                        'material_id' => $item['material_id'] ?? null,
                        'name' => $item['material_name'] ?? 'Материал',
                        'code' => $item['material_code'] ?? null,
                        'category' => $item['category'] ?? null,
                        'measurement_unit' => $item['measurement_unit'] ?? null,
                        'available_quantity' => (float) ($item['available_quantity'] ?? 0),
                        'reserved_quantity' => (float) ($item['reserved_quantity'] ?? 0),
                        'total_value' => (float) ($item['total_value'] ?? 0),
                        'is_low_stock' => (bool) ($item['is_low_stock'] ?? false),
                        'location_code' => $item['location_code'] ?? null,
                        'photo_url' => $photo['url'] ?? null,
                    ];
                })
                ->values()
                ->all(),
            'movements' => $movements->take(6)->values()->all(),
            'requests' => $requests,
            'procurement' => $procurement,
            'deliveries' => [
                'active' => $activeDeliveriesCount,
                'problem' => $problemDeliveriesCount,
            ],
            'alerts' => [
                'low_stock' => $lowStock->count(),
                'blocked_tasks' => $blockedTasksCount,
                'expiring_reservations' => $expiringReservationsCount,
                'overdue_material_requests' => $requests['overdue_material'],
                'problem_deliveries' => $problemDeliveriesCount,
            ],
        ];
    }

    private function buildMaterialRequests(int $organizationId): array
    {
        $activeStatuses = array_values(array_filter(
            array_map(static fn (SiteRequestStatusEnum $status): string => $status->value, SiteRequestStatusEnum::cases()),
            static fn (string $status): bool => ! in_array($status, [
                SiteRequestStatusEnum::DRAFT->value,
                SiteRequestStatusEnum::COMPLETED->value,
                SiteRequestStatusEnum::CANCELLED->value,
                SiteRequestStatusEnum::REJECTED->value,
            ], true)
        ));

        $query = SiteRequest::query()
            ->where('organization_id', $organizationId)
            ->where('request_type', SiteRequestTypeEnum::MATERIAL_REQUEST->value)
            ->whereIn('status', $activeStatuses);

        $pendingCount = (clone $query)
            ->whereIn('status', [
                SiteRequestStatusEnum::PENDING->value,
                SiteRequestStatusEnum::IN_REVIEW->value,
                SiteRequestStatusEnum::APPROVED->value,
            ])
            ->count();
        $urgentCount = (clone $query)
            ->whereIn('priority', [SiteRequestPriorityEnum::HIGH->value, SiteRequestPriorityEnum::URGENT->value])
            ->count();
        $overdueCount = (clone $query)
            ->whereNotNull('required_date')
            ->where('required_date', '<', now()->toDateString())
            ->count();

        $latest = (clone $query)
            ->with('project:id,name')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 4 WHEN 'high' THEN 3 WHEN 'medium' THEN 2 ELSE 1 END DESC")
            ->orderBy('required_date')
            ->orderByDesc('created_at')
            ->limit(4)
            ->get()
            ->map(static function (SiteRequest $request): array {
                $status = $request->status instanceof SiteRequestStatusEnum
                    ? $request->status
                    : SiteRequestStatusEnum::tryFrom((string) $request->status) ?? SiteRequestStatusEnum::PENDING;
                $priority = $request->priority instanceof SiteRequestPriorityEnum
                    ? $request->priority
                    : SiteRequestPriorityEnum::tryFrom((string) $request->priority) ?? SiteRequestPriorityEnum::MEDIUM;

                return [
                    'id' => $request->id,
                    'title' => $request->title,
                    'project_name' => $request->project?->name,
                    'material_name' => $request->material_name,
                    'quantity' => $request->material_quantity !== null ? (float) $request->material_quantity : null,
                    'unit' => $request->material_unit,
                    'required_date' => optional($request->required_date)?->toDateString(),
                    'status' => $status->value,
                    'status_label' => $status->label(),
                    'priority' => $priority->value,
                    'priority_label' => $priority->label(),
                ];
            })
            ->values()
            ->all();

        return [
            'pending_material' => $pendingCount,
            'urgent_material' => $urgentCount,
            'overdue_material' => $overdueCount,
            'latest' => $latest,
        ];
    }

    private function buildProcurement(int $organizationId): array
    {
        $purchaseOrdersInWork = [
            PurchaseOrderStatusEnum::CONFIRMED->value,
            PurchaseOrderStatusEnum::IN_DELIVERY->value,
            PurchaseOrderStatusEnum::PARTIALLY_DELIVERED->value,
        ];

        return [
            'pending_purchase_requests' => PurchaseRequest::query()
                ->where('organization_id', $organizationId)
                ->whereIn('status', [
                    PurchaseRequestStatusEnum::DRAFT->value,
                    PurchaseRequestStatusEnum::PENDING->value,
                ])
                ->count(),
            'purchase_orders_in_work' => PurchaseOrder::query()
                ->where('organization_id', $organizationId)
                ->whereIn('status', $purchaseOrdersInWork)
                ->count(),
            'purchase_orders_due' => PurchaseOrder::query()
                ->where('organization_id', $organizationId)
                ->whereIn('status', $purchaseOrdersInWork)
                ->whereDate('delivery_date', '<=', now()->toDateString())
                ->count(),
        ];
    }
}
