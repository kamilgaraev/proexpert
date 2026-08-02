<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\HandoverAcceptance\Reporting\Readiness\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class HandoverEvidenceFact
{
    public function __construct(
        public string $eventType,
        public string $sourceType,
        public int $sourceId,
        public ?string $sourceCode,
        public string $status,
        public CarbonImmutable $occurredAt,
        public int $sourceVersion = 1,
    ) {
        if (
            preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $eventType) !== 1
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sourceType) !== 1
            || $sourceId < 1
            || ($sourceCode !== null && preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $sourceCode) !== 1)
            || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $status) !== 1
            || $sourceVersion < 1
        ) {
            throw new InvalidArgumentException('handover_evidence_fact_invalid');
        }
    }
}
