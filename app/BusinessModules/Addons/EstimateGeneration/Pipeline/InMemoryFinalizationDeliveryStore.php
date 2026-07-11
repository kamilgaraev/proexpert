<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use RuntimeException;

final class InMemoryFinalizationDeliveryStore implements FinalizationDeliveryStore
{
    /** @var array<string, FinalizationDeliveryReceipt> */
    private array $delivered = [];

    public function deliverOnce(FinalizationDeliveryReceipt $receipt, callable $deliver): void
    {
        $existing = $this->delivered[$receipt->businessKey] ?? null;
        if ($existing !== null) {
            if ($existing != $receipt) {
                throw new RuntimeException('estimate_generation.finalization_delivery_identity_collision');
            }

            return;
        }
        $deliver();
        $this->delivered[$receipt->businessKey] = $receipt;
    }
}
