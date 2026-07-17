<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentUnitDispatchCandidate
{
    public function __construct(
        public int $unitId,
        public string $sourceVersion,
        public bool $priority = false,
    ) {}
}
