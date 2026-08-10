<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Understanding;

interface CrossDocumentFactArbitrator
{
    public function arbitrate(string $operationIdentity, array $payload, array $scope): array;
}
