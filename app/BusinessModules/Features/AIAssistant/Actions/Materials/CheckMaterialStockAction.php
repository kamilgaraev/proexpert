<?php

namespace App\BusinessModules\Features\AIAssistant\Actions\Materials;

use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsLowStockWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\Services\Widgets\Providers\Materials\MaterialsInventoryWidgetProvider;
use App\BusinessModules\Features\AdvancedDashboard\DTOs\WidgetDataRequest;
use App\BusinessModules\Features\AdvancedDashboard\Enums\WidgetType;

class CheckMaterialStockAction
{
    protected MaterialsLowStockWidgetProvider $lowStockProvider;
    protected MaterialsInventoryWidgetProvider $inventoryProvider;

    public function __construct(
        MaterialsLowStockWidgetProvider $lowStockProvider,
        MaterialsInventoryWidgetProvider $inventoryProvider
    ) {
        $this->lowStockProvider = $lowStockProvider;
        $this->inventoryProvider = $inventoryProvider;
    }

    public function execute(int $organizationId, ?array $params = []): array
    {
        $lowStockRequest = new WidgetDataRequest(
            widgetType: WidgetType::MATERIALS_LOW_STOCK,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $inventoryRequest = new WidgetDataRequest(
            widgetType: WidgetType::MATERIALS_INVENTORY,
            organizationId: $organizationId,
            filters: $params['filters'] ?? [],
        );

        $lowStock = $this->lowStockProvider->getData($lowStockRequest);
        $inventory = $this->inventoryProvider->getData($inventoryRequest);

        return [
            'low_stock_items' => $lowStock->data['materials'] ?? [],
            'low_stock_count' => count($lowStock->data['materials'] ?? []),
            'total_inventory_value' => $inventory->data['total_value'] ?? 0,
            'total_items' => $inventory->data['total_items'] ?? 0,
        ];
    }
}

