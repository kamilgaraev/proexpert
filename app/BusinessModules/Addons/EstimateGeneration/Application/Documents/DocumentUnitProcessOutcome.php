<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use DateTimeImmutable;

final readonly class DocumentUnitProcessOutcome
{
    public function __construct(
        public DocumentProcessingUnitClaimStatus $status,
        public ?DateTimeImmutable $retryAt = null,
    ) {}
}
