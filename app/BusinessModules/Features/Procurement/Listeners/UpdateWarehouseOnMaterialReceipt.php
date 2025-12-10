<?php

namespace App\BusinessModules\Features\Procurement\Listeners;

use App\BusinessModules\Features\Procurement\Events\MaterialReceivedFromSupplier;
use App\Modules\Core\AccessController;

/**
 * Слушатель для обновления склада при получении материалов от поставщика
 */
class UpdateWarehouseOnMaterialReceipt
{
    public function __construct(
        private readonly AccessController $accessController
    ) {}

    /**
     * Handle the event.
     */
    public function handle(MaterialReceivedFromSupplier $event): void
    {
        $order = $event->purchaseOrder;
        $warehouseId = $event->warehouseId;
        $items = $event->items;

        // Проверяем активацию модуля basic-warehouse
        if (!$this->accessController->hasModuleAccess($order->organization_id, 'basic-warehouse')) {
            \Log::warning('procurement.skip_warehouse_update', [
                'purchase_order_id' => $order->id,
                'reason' => 'Модуль склада не активирован',
            ]);
            return;
        }

        // Проверяем настройки модуля
        $module = app(\App\BusinessModules\Features\Procurement\ProcurementModule::class);
        $settings = $module->getSettings($order->organization_id);

        if (!($settings['auto_receive_to_warehouse'] ?? true)) {
            \Log::info('procurement.skip_warehouse_update', [
                'purchase_order_id' => $order->id,
                'reason' => 'Автоматический прием на склад отключен в настройках',
            ]);
            return;
        }

        try {
            $warehouseService = app(\App\BusinessModules\Features\BasicWarehouse\Services\WarehouseService::class);

            foreach ($items as $itemData) {
                // Получаем PurchaseOrderItem
                $orderItem = \App\BusinessModules\Features\Procurement\Models\PurchaseOrderItem::find($itemData['item_id']);

                if (!$orderItem || !$orderItem->material_id) {
                    \Log::warning('procurement.material_not_found', [
                        'item_id' => $itemData['item_id'],
                        'purchase_order_id' => $order->id,
                    ]);
                    continue;
                }

                // ПРАВИЛЬНЫЙ вызов с 6 параметрами
                $warehouseService->receiveAsset(
                    $order->organization_id,           // int $organizationId
                    $warehouseId,                      // int $warehouseId
                    $orderItem->material_id,           // int $materialId
                    $itemData['quantity_received'],   // float $quantity
                    $itemData['price'],                // float $price
                    [                                  // array $metadata
                        'project_id' => $order->purchaseRequest?->siteRequest?->project_id,
                        'user_id' => $event->userId,
                        'document_number' => $order->order_number,
                        'reason' => "Прием материалов по заказу поставщику #{$order->order_number}",
                        'source_type' => 'procurement',
                        'source_id' => $order->id,
                        'purchase_order_item_id' => $orderItem->id,
                    ]
                );

                \Log::info('procurement.warehouse.item_received', [
                    'purchase_order_id' => $order->id,
                    'order_item_id' => $orderItem->id,
                    'material_id' => $orderItem->material_id,
                    'quantity' => $itemData['quantity_received'],
                    'price' => $itemData['price'],
                    'warehouse_id' => $warehouseId,
                ]);
            }

            // Обновляем метаданные SiteRequest если есть
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

            \Log::info('procurement.warehouse.updated', [
                'purchase_order_id' => $order->id,
                'warehouse_id' => $warehouseId,
                'items_count' => count($items),
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.warehouse.update_failed', [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

