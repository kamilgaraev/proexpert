<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

final readonly class DocumentProcessingUnitClaim
{
    public function __construct(
        public int $unitId,
        public bool $acquired,
        public ?string $token = null,
    ) {}
}
