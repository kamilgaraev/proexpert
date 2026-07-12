<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

interface GeometryRegenerationIntentStore
{
    public function append(GeometryRegenerationIntent $intent): int;

    public function deliver(int $intentId): bool;

    /** @return array{claimed:int,delivered:int,failed:int} */
    public function recover(int $limit = 100): array;
}
