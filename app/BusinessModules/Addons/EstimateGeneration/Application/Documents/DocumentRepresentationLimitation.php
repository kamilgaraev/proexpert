<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentRepresentationLimitation
{
    public function __construct(
        public string $capability,
        public string $reason,
    ) {}
}
