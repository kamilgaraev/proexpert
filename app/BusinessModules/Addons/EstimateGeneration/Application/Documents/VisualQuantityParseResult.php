<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class VisualQuantityParseResult
{
    public function __construct(
        public string $status,
        public ?int $quantity,
        public string $provenance,
    ) {}
}
