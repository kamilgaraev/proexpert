<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\WorkforceManagement\Reporting;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Contracts\EffectiveAssignmentSource;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\EffectiveAssignmentFact;
use App\BusinessModules\Features\WorkforceManagement\Reporting\DTO\EffectiveAssignmentResolution;
use DateTimeImmutable;
use DomainException;

final readonly class EffectiveAssignmentResolver
{
    public function __construct(private EffectiveAssignmentSource $source)
    {
    }

    public function forDate(
        int $organizationId,
        int $employeeId,
        DateTimeImmutable $date,
    ): EffectiveAssignmentResolution {
        $active = array_values(array_filter(
            $this->source->forEmployee($organizationId, $employeeId),
            static fn (EffectiveAssignmentFact $fact): bool => $fact->organizationId === $organizationId
                && $fact->employeeId === $employeeId
                && $fact->validFrom <= $date
                && ($fact->validToExclusive === null || $date < $fact->validToExclusive),
        ));

        $unique = [];
        $discarded = 0;
        foreach ($active as $fact) {
            $identity = $fact->identity();
            if (isset($unique[$identity])) {
                ++$discarded;
                continue;
            }
            $unique[$identity] = $fact;
        }

        $resolved = array_values($unique);
        if (count($resolved) > 1) {
            throw new DomainException('WORKFORCE_ASSIGNMENT_OVERLAP');
        }

        return new EffectiveAssignmentResolution($resolved, $discarded);
    }
}
