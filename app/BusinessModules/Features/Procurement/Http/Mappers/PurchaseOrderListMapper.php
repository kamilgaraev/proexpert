<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Mappers;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use App\BusinessModules\Features\Procurement\Models\PurchaseReceipt;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

final class PurchaseOrderListMapper
{
    public function prepare(LengthAwarePaginator $page): LengthAwarePaginator
    {
        foreach ($page->getCollection() as $order) {
            if (! $order instanceof PurchaseOrder || ! $order->relationLoaded('receipts')) {
                continue;
            }

            $orderId = (int) $order->getKey();
            $organizationId = (int) $order->organization_id;
            $receipts = $order->getRelation('receipts');
            if ($orderId <= 0 || $organizationId <= 0 || ! $receipts instanceof Collection) {
                continue;
            }

            foreach ($receipts as $receipt) {
                if ($receipt instanceof PurchaseReceipt && ! $receipt->relationLoaded('purchaseOrder')
                    && (int) $receipt->purchase_order_id === $orderId
                    && (int) $receipt->organization_id === $organizationId) {
                    $receipt->setRelation('purchaseOrder', $order);
                }
            }
        }

        return $page;
    }
}
