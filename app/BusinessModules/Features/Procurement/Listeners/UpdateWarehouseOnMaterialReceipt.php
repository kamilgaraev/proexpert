<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier;
use App\Modules\Core\AccessController;
use function trans_message;

class UpdateWarehouseOnMaterialReceipt
{
    public function __construct(
        private readonly AccessController $accessController
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

        foreach ($items as $itemData) {
            $orderItem = $order->items()->find($itemData['item_id']);

            if (!$orderItem || !$orderItem->material_id) {
                throw new \DomainException(trans_message('procurement.purchase_orders.item_not_found'));
            }

            $warehouseService->receiveAsset(
                $order->organization_id,
                $warehouseId,
                $orderItem->material_id,
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
            $siteRequest->update([
                'metadata' => array_merge($siteRequest->metadata ?? [], [
                    'materials_received' => true,
                    'received_at' => now()->toDateTimeString(),
                    'warehouse_id' => $warehouseId,
                    'purchase_order_id' => $order->id,
                ]),
            ]);
        }
    }
}
