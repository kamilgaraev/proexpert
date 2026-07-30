<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseBalance;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use App\BusinessModules\Features\BasicWarehouse\Reporting\InventoryRisk\Services\WarehouseInventoryEventRecorder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptInventoryLot;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceiptLine;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\Cache;

final readonly class PurchaseReceiptInventoryService
{
    public function __construct(private WarehouseInventoryEventRecorder $inventoryEvents) {}

    public function reverse(
        PurchaseReceiptLine $line,
        string $reasonCode,
        int $actorId,
        CarbonImmutable $occurredAt,
    ): WarehouseMovement {
        $lot = PurchaseReceiptInventoryLot::query()
            ->where('organization_id', $line->purchaseReceipt->organization_id)
            ->where('purchase_receipt_line_id', $line->id)
            ->lockForUpdate()
            ->first();
        if (! $lot instanceof PurchaseReceiptInventoryLot) {
            throw new DomainException(trans_message('procurement.purchase_orders.receipt_inventory_lot_required'));
        }
        if (BigDecimal::of((string) $lot->reversed_quantity)->isPositive()) {
            throw new DomainException(trans_message('procurement.purchase_orders.receipt_line_already_reversed'));
        }

        $balance = WarehouseBalance::query()
            ->where('organization_id', $lot->organization_id)
            ->whereKey($lot->warehouse_balance_id)
            ->where('batch_number', 'purchase-receipt-line:'.$line->id)
            ->lockForUpdate()
            ->first();
        if (! $balance instanceof WarehouseBalance
            || BigDecimal::of((string) $balance->available_quantity)
                ->isLessThan(BigDecimal::of((string) $lot->original_quantity))) {
            throw new DomainException(trans_message('procurement.purchase_orders.receipt_inventory_lot_unavailable'));
        }

        $sourceMovement = WarehouseMovement::query()
            ->where('organization_id', $lot->organization_id)
            ->whereKey($lot->receipt_warehouse_movement_id)
            ->lockForUpdate()
            ->firstOrFail();
        $itemMaterialId = (int) $line->purchaseOrderItem->material_id;
        if ((int) $balance->material_id !== $itemMaterialId
            || (int) $sourceMovement->material_id !== $itemMaterialId
            || (int) $balance->warehouse_id !== (int) $line->purchaseReceipt->warehouse_id
            || (int) $sourceMovement->warehouse_id !== (int) $balance->warehouse_id) {
            throw new DomainException(trans_message('procurement.purchase_orders.receipt_inventory_lot_invalid'));
        }
        $metadata = is_array($sourceMovement->metadata) ? $sourceMovement->metadata : [];
        unset($metadata['reporting_opening_basis']);
        $balance->available_quantity = (string) BigDecimal::of((string) $balance->available_quantity)
            ->minus((string) $lot->original_quantity);
        $balance->last_movement_at = $occurredAt;
        $balance->save();

        $movement = WarehouseMovement::query()->create([
            'organization_id' => $lot->organization_id,
            'warehouse_id' => $balance->warehouse_id,
            'cell_id' => $balance->cell_id,
            'material_id' => $balance->material_id,
            'movement_type' => 'write_off',
            'quantity' => $lot->original_quantity,
            'price' => $balance->unit_price,
            'project_id' => $sourceMovement->project_id,
            'user_id' => $actorId,
            'document_number' => $sourceMovement->document_number,
            'reason' => $reasonCode,
            'operation_category' => 'procurement_receipt_reversal',
            'metadata' => array_merge($metadata, [
                'reversed_purchase_receipt_line_id' => (int) $line->id,
                'reversed_receipt_movement_id' => (int) $sourceMovement->id,
            ]),
            'movement_date' => $occurredAt,
        ]);
        $this->inventoryEvents->record($movement, 'issue', null);
        $lot->forceFill(['reversed_quantity' => $lot->original_quantity])->save();
        Cache::forget("warehouse_stock_{$lot->organization_id}");
        Cache::forget("warehouse_low_stock_{$lot->organization_id}");

        return $movement;
    }
}
