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
        $materials = $event->materials;

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

            foreach ($materials as $material) {
                $warehouseService->receiveAsset([
                    'organization_id' => $order->organization_id,
                    'project_id' => $order->purchaseRequest?->siteRequest?->project_id,
                    'material_id' => $material['material_id'] ?? null,
                    'material_name' => $material['name'] ?? $order->purchaseRequest?->siteRequest?->material_name,
                    'quantity' => $material['quantity'] ?? 0,
                    'unit' => $material['unit'] ?? 'шт',
                    'source_type' => 'procurement',
                    'source_id' => $order->id,
                    'notes' => "Прием материалов по заказу поставщику: {$order->order_number}",
                ]);
            }

            \Log::info('procurement.warehouse.updated', [
                'purchase_order_id' => $order->id,
                'materials_count' => count($materials),
            ]);
        } catch (\Exception $e) {
            \Log::error('procurement.warehouse.update_failed', [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

