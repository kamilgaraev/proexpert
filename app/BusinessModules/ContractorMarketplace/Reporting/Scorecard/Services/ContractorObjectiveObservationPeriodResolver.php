<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

final readonly class ContractorObjectiveObservationPeriodResolver
{
    public function resolve(array $row, string $sourceReportCode): string
    {
        $field = match ($sourceReportCode) {
            'baseline_schedule_variance' => 'planned_start',
            'supply_reliability' => 'original_promised_at',
            'quality_defect_flow' => 'cohort_date',
            'safety_incident_actions' => 'event_date',
            default => throw new InvalidArgumentException('contractor_objective_source_invalid'),
        };
        $observedAt = $row[$field] ?? null;
        if (
            (! is_string($observedAt) && ! $observedAt instanceof DateTimeInterface)
            || (is_string($observedAt) && trim($observedAt) === '')
        ) {
            throw new InvalidArgumentException('contractor_objective_observation_period_missing');
        }
        try {
            return CarbonImmutable::parse($observedAt)->toISOString();
        } catch (Throwable $exception) {
            throw new InvalidArgumentException(
                'contractor_objective_observation_period_invalid',
                previous: $exception,
            );
        }
    }
}
