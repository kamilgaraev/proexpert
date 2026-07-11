<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

interface RerankWireClient
{
    public function provider(): string;

    /** @param array<int, array<string, mixed>> $messages @param array<string, mixed> $options @return array<string, mixed> */
    public function call(string $model, array $messages, array $options): array;
}
