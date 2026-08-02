<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Temporal;

use DateTimeImmutable;
use DomainException;

final readonly class TemporalOwnerFactResolver
{
    public function payloadAt(iterable $facts, DateTimeImmutable $asOf): ?array
    {
        $effective = $this->effectiveFact($facts, $asOf);
        if ($effective === null || (string) $effective->operation === 'delete') {
            return null;
        }

        return is_array($effective->payload)
            ? $effective->payload
            : json_decode((string) $effective->payload, true, 512, JSON_THROW_ON_ERROR);
    }

    public function hasCoverageAt(iterable $facts, DateTimeImmutable $asOf): bool
    {
        return $this->effectiveFact($facts, $asOf) !== null;
    }

    public function payloadsAt(
        iterable $facts,
        DateTimeImmutable $asOf,
        array $projectIds = [],
    ): array {
        $grouped = [];
        foreach ($facts as $fact) {
            $grouped[(int) $fact->source_id][] = $fact;
        }

        $payloads = [];
        foreach ($grouped as $sourceId => $sourceFacts) {
            $payload = $this->payloadAt($sourceFacts, $asOf);
            if ($payload === null) {
                continue;
            }
            $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : null;
            if ($projectIds !== [] && $projectId !== null && ! in_array($projectId, $projectIds, true)) {
                continue;
            }
            $payloads[$sourceId] = $payload;
        }
        ksort($payloads, SORT_NUMERIC);

        return $payloads;
    }

    public function assertExactState(array $temporalPayloads, array $currentPayloads): void
    {
        ksort($temporalPayloads, SORT_NUMERIC);
        ksort($currentPayloads, SORT_NUMERIC);
        if ($temporalPayloads !== $currentPayloads) {
            throw new DomainException('REPORT_TEMPORAL_OWNER_FACT_GAP');
        }
    }

    private function effectiveFact(iterable $facts, DateTimeImmutable $asOf): ?object
    {
        $effective = null;
        $effectiveAt = null;
        $effectiveSequence = 0;

        foreach ($facts as $fact) {
            $recordedAt = new DateTimeImmutable((string) $fact->recorded_at);
            $sequence = (int) $fact->sequence;
            if ($recordedAt > $asOf
                || ($effectiveAt !== null
                    && ($recordedAt < $effectiveAt
                        || ($recordedAt == $effectiveAt && $sequence <= $effectiveSequence)))) {
                continue;
            }

            $effective = $fact;
            $effectiveAt = $recordedAt;
            $effectiveSequence = $sequence;
        }

        return $effective;
    }
}
