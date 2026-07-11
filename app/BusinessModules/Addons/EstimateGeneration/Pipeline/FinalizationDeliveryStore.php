<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

interface FinalizationDeliveryStore
{
    /** @param callable(): object $deliver */
    public function deliverOnce(FinalizationDeliveryReceipt $receipt, callable $deliver): void;
}
