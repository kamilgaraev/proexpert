<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\BasicWarehouse\Models\Asset;
use App\BusinessModules\Features\BasicWarehouse\Services\AssetService;
use App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier;
use App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem;
use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestStatusEnum;
use App\BusinessModules\Features\SiteRequests\Models\SiteRequest;
use App\BusinessModules\Features\SiteRequests\Services\SiteRequestService;
use App\Models\MeasurementUnit;
use App\Modules\Core\AccessController;
use DomainException;
use Illuminate\Support\Facades\Log;

use function trans_message;

class UpdateWarehouseOnMaterialReceipt
{
    public function __construct(
        private readonly AccessController $accessController,
        private readonly AssetService $assetService,
        private readonly SiteRequestService $siteRequestService
    ) {
    }

    public function handle(MaterialReceivedFromSupplier $event): void
    {
        $order = $event->purchaseOrder;
        $warehouseId = $event->warehouseId;
        $items = $event->items;

        if (!$this->accessController->hasModuleAccess($order->organization_id, 'basic-warehouse')) {
            throw new \DomainException(trans_message('procurement.purchase_orders.receive_error'));
        }

        $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);
        $receivedMaterialIds = [];

        foreach ($items as $itemData) {
            $orderItem = $order->items()->find($itemData['item_id']);

            if (!$orderItem) {
                throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
            }

            $materialId = $this->resolveMaterialId(
                $orderItem,
                (int) $order->organization_id,
                (float) $itemData['price']
            );
            $receivedMaterialIds[] = $materialId;

            $warehouseService->receiveAsset(
                $order->organization_id,
                $warehouseId,
                $materialId,
                (float) $itemData['quantity_received'],
                (float) $itemData['price'],
                [
                    'project_id' => $order->purchaseRequest?->siteRequest?->project_id,
                    'user_id' => $event->userId,
                    'document_number' => $order->order_number,
                    'reason' => "Прием материалов по заказу поставщику #{$order->order_number}",
                    'source_type' => 'procurement',
                    'source_id' => $order->id,
                    'purchase_order_item_id' => $orderItem->id,
                ]
            );
        }

        $siteRequest = $order->purchaseRequest?->siteRequest;
        if ($siteRequest) {
            $metadata = [
                'metadata' => array_merge($siteRequest->metadata ?? [], [
                    'materials_received' => true,
                    'received_at' => now()->toDateTimeString(),
                    'warehouse_id' => $warehouseId,
                    'purchase_order_id' => $order->id,
                ]),
            ];

            $uniqueMaterialIds = array_values(array_unique($receivedMaterialIds));

            if ($siteRequest->material_id === null && count($uniqueMaterialIds) === 1) {
                $metadata['material_id'] = $uniqueMaterialIds[0];
            }

            $siteRequest->update($metadata);
            $this->completeSiteRequestIfReady($siteRequest->fresh() ?? $siteRequest, $event->userId);
        }
    }

    private function resolveMaterialId(PurchaseOrderItem $orderItem, int $organizationId, float $defaultPrice): int
    {
        if ($orderItem->material_id !== null) {
            return (int) $orderItem->material_id;
        }

        $materialName = trim((string) $orderItem->material_name);

        if ($materialName === '') {
            throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
        }

        $asset = Asset::query()
            ->where('organization_id', $organizationId)
            ->where('name', $materialName)
            ->first();

        if (! $asset) {
            $asset = $this->assetService->createAsset($organizationId, [
                'name' => $materialName,
                'measurement_unit_id' => $this->resolveMeasurementUnitId($organizationId, $orderItem->unit),
                'category' => null,
                'default_price' => $defaultPrice,
                'asset_type' => Asset::TYPE_MATERIAL,
                'is_active' => true,
            ]);
        }

        $orderItem->forceFill(['material_id' => $asset->id])->save();

        return (int) $asset->id;
    }

    private function resolveMeasurementUnitId(int $organizationId, ?string $unitName): int
    {
        $unitName = trim((string) $unitName);

        if ($unitName !== '' && ! str_contains($unitName, '?')) {
            $unit = MeasurementUnit::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'material')
                ->where(function ($query) use ($unitName): void {
                    $query->where('short_name', $unitName)
                        ->orWhere('name', $unitName);
                })
                ->first();

            if ($unit) {
                return (int) $unit->id;
            }
        }

        $unit = MeasurementUnit::query()
            ->where('organization_id', $organizationId)
            ->where('type', 'material')
            ->where('is_default', true)
            ->first()
            ?? MeasurementUnit::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'material')
                ->first();

        if ($unit) {
            return (int) $unit->id;
        }

        $fallbackUnitName = $unitName !== '' && ! str_contains($unitName, '?') ? $unitName : 'pcs';

        return (int) MeasurementUnit::query()->create([
            'organization_id' => $organizationId,
            'name' => $fallbackUnitName,
            'short_name' => $fallbackUnitName,
            'type' => 'material',
            'is_default' => true,
            'is_system' => false,
        ])->id;
    }

    private function completeSiteRequestIfReady(SiteRequest $siteRequest, int $userId): void
    {
        if ($siteRequest->status === SiteRequestStatusEnum::COMPLETED) {
            return;
        }

        if (! in_array($siteRequest->status, [
            SiteRequestStatusEnum::APPROVED,
            SiteRequestStatusEnum::FULFILLED,
        ], true)) {
            return;
        }

        try {
            $this->siteRequestService->changeStatus(
                $siteRequest,
                $userId,
                SiteRequestStatusEnum::COMPLETED->value
            );
        } catch (DomainException $exception) {
            Log::warning('site_request.complete_on_material_receipt.workflow_blocked', [
                'site_request_id' => $siteRequest->id,
                'purchase_order_id' => $siteRequest->purchaseOrders()->latest('purchase_orders.id')->value('purchase_orders.id'),
                'status' => $siteRequest->status->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
