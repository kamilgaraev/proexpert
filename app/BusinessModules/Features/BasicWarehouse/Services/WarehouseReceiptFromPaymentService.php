<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BasicWarehouse\Services;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Features\BasicWarehouse\Models\OrganizationWarehouse;
use App\BusinessModules\Features\BasicWarehouse\Models\WarehouseMovement;
use Illuminate\Support\Facades\Log;

class WarehouseReceiptFromPaymentService
{
    public function createFromPaymentDocument(PaymentDocument $document): void
    {
        $document->load(['estimateSplits.estimateItem']);

        if ($document->estimateSplits->isEmpty()) {
            return;
        }

        $warehouse = OrganizationWarehouse::where('organization_id', $document->organization_id)
            ->where('is_default', true)
            ->first();

        if (!$warehouse) {
            $warehouse = OrganizationWarehouse::where('organization_id', $document->organization_id)->first();
        }

        if (!$warehouse) {
            Log::warning('warehouse_receipt.no_warehouse_found', [
                'document_id' => $document->id,
                'organization_id' => $document->organization_id,
            ]);
            return;
        }

        foreach ($document->estimateSplits as $split) {
            $estimateItem = $split->estimateItem;
            if (!$estimateItem || !$estimateItem->isMaterial()) {
                continue;
            }

            WarehouseMovement::create([
                'organization_id' => $document->organization_id,
                'warehouse_id' => $warehouse->id,
                'material_id' => $estimateItem->catalog_item_id,
                'movement_type' => WarehouseMovement::TYPE_RECEIPT,
                'quantity' => (float) $split->quantity,
                'price' => (float) $split->unit_price_actual,
                'project_id' => $document->project_id,
                'document_number' => $document->document_number,
                'reason' => "Автоприход по оплате счёта {$document->document_number}",
                'movement_date' => $document->paid_at ?? now(),
                'metadata' => [
                    'payment_document_id' => $document->id,
                    'estimate_item_id' => $estimateItem->id,
                    'split_id' => $split->id,
                ],
            ]);

            $estimateItem->update(['procurement_status' => 'delivered']);
        }

        Log::info('warehouse_receipt.created_from_payment', [
            'document_id' => $document->id,
            'document_number' => $document->document_number,
            'splits_processed' => $document->estimateSplits->count(),
        ]);
    }
}
