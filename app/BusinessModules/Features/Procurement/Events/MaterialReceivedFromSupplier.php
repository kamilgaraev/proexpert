<?php

namespace App\BusinessModules\Features\Procurement\Events;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Событие получения материалов от поставщика
 * 
 * Отправляется при приеме материалов на склад
 * Триггерит обновление склада через UpdateWarehouseOnMaterialReceipt listener
 */
class MaterialReceivedFromSupplier
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public PurchaseOrder $purchaseOrder,
        public int $warehouseId,
        public array $items,
        public int $userId
    ) {}
}


