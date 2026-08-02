<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use DateTimeImmutable;

final readonly class PayrollVersionTransitionResolver
{
    public function at(iterable $transitions, DateTimeImmutable $asOf): ?string
    {
        $effective = null;
        $effectiveAt = null;
        $effectiveId = 0;

        foreach ($transitions as $transition) {
            $transitionedAt = new DateTimeImmutable((string) $transition->transitioned_at);
            $id = (int) $transition->id;
            if ($transitionedAt > $asOf
                || ($effectiveAt !== null
                    && ($transitionedAt < $effectiveAt
                        || ($transitionedAt == $effectiveAt && $id <= $effectiveId)))) {
                continue;
            }

            $effective = (string) $transition->status;
            $effectiveAt = $transitionedAt;
            $effectiveId = $id;
        }

        return $effective;
    }
}
