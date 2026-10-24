<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\DTOs;

use App\BusinessModules\Features\Procurement\Models\PurchaseOrder;
use Illuminate\Support\Collection;

final readonly class PurchaseOrderPaymentReadContext
{
    public function __construct(
        private array $documents,
        private array $receivedAmounts,
    ) {}

    public function hasOrder(PurchaseOrder $order): bool
    {
        return array_key_exists($this->key($order), $this->documents);
    }

    public function documentsFor(PurchaseOrder $order): Collection
    {
        return new Collection($this->documents[$this->key($order)] ?? []);
    }

    public function receivedAmountFor(PurchaseOrder $order): float
    {
        return $this->receivedAmounts[$this->key($order)] ?? 0.0;
    }

    private function key(PurchaseOrder $order): string
    {
        return $order->organization_id.':'.$order->getKey();
    }
}
