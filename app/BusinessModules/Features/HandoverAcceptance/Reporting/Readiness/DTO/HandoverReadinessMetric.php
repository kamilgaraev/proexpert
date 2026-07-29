<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO;

final readonly class HandoverReadinessMetric
{
    public function __construct(
        public string $mandatoryCompleteness,
        public string $documentCompleteness,
        public int $openHardBlockerCount,
        public int $attemptCount,
        public int $successfulResultCount,
        public bool $ready,
    ) {
    }
}
