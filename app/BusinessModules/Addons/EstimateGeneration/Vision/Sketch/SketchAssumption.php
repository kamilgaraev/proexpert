<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Sketch;

final readonly class SketchAssumption
{
    public function __construct(
        public string $key,
        public int|float|string $value,
        public string $source,
        public float $confidence,
        public ?string $evidenceId,
        public bool $requiresConfirmation,
        public bool $evidenced,
    ) {}
}
