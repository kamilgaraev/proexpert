<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

interface DocumentUnitAggregateReconciler
{
    public function reconcile(int $documentId, string $sourceVersion): void;
}
