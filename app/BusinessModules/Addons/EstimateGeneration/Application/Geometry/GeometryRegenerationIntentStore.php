<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Geometry;

interface GeometryRegenerationIntentStore
{
    public function append(GeometryRegenerationIntent $intent): int;

    public function deliver(int $intentId): void;

    public function recover(int $limit = 100): int;
}
