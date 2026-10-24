<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Http\Resources;

use App\BusinessModules\Features\Procurement\DTOs\PurchaseRequestListItem;
use App\BusinessModules\Features\Procurement\Services\PurchaseOrderPaymentGateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PurchaseRequestListResourceCollection extends AnonymousResourceCollection
{
    public function toArray(Request $request): array
    {
        if ($this->collection->isEmpty()) {
            return parent::toArray($request);
        }

        $items = $this->collection->map(
            static fn (PurchaseRequestResource $resource): ?PurchaseRequestListItem => $resource->preparedListItem()
        );
        if ($items->containsStrict(null)) {
            return parent::toArray($request);
        }

        $orders = new \Illuminate\Database\Eloquent\Collection($items->flatMap(
            static fn (PurchaseRequestListItem $item) => $item->purchaseRequest->purchaseOrders
        )->all());
        $paymentReadContext = app(PurchaseOrderPaymentGateService::class)->batchForListSerialization($orders);
        $resourceClass = $this->collects;

        return $items->map(static fn (PurchaseRequestListItem $item): array => (new $resourceClass(
            $item,
            paymentReadContext: $paymentReadContext,
        ))->toArray($request))->all();
    }
}
