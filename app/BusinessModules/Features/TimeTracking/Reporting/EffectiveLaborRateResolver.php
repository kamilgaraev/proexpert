<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\TimeTracking\Reporting;

use App\BusinessModules\Features\TimeTracking\Reporting\Contracts\EffectiveLaborRateSource;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateFact;
use App\BusinessModules\Features\TimeTracking\Reporting\DTO\EffectiveLaborRateResolution;
use DateTimeImmutable;
use DomainException;

final readonly class EffectiveLaborRateResolver
{
    public function __construct(private EffectiveLaborRateSource $source)
    {
    }

    public function atDate(
        int $organizationId,
        int $employeeId,
        DateTimeImmutable $workDate,
    ): ?EffectiveLaborRateResolution {
        $active = array_values(array_filter(
            $this->source->forEmployee($organizationId, $employeeId),
            static fn (EffectiveLaborRateFact $fact): bool => $fact->organizationId === $organizationId
                && $fact->employeeId === $employeeId
                && $fact->validFrom <= $workDate
                && ($fact->validToExclusive === null || $workDate < $fact->validToExclusive),
        ));

        $unique = [];
        foreach ($active as $fact) {
            $unique[$fact->identity()] = $fact;
        }
        $active = array_values($unique);

        if (count($active) > 1) {
            throw new DomainException('LABOR_RATE_OVERLAP');
        }
        if ($active === []) {
            return null;
        }

        $rate = $active[0];

        return new EffectiveLaborRateResolution(
            rateId: $rate->rateId,
            amount: $rate->amount,
            currency: $rate->currency,
            rateType: $rate->rateType,
            sourceVersion: $rate->sourceVersion,
            quality: $rate->currency === null ? 'missing_currency' : 'complete',
        );
    }
}
