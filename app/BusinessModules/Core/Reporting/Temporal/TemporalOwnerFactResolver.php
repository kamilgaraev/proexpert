<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Temporal;

use DateTimeImmutable;

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
