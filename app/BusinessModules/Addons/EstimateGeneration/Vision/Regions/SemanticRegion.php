<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Regions;

final readonly class SemanticRegion
{
    /** @param array{0:float,1:float,2:float,3:float} $box */
    public function __construct(
        public string $id,
        public string $label,
        public string $purpose,
        public array $box,
        public int $pixelCount,
    ) {}
}
