<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\Services;

use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentMetric;
use App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO\ContractorComponentSignal;
use InvalidArgumentException;

final readonly class ContractorScorecardFormula
{
    private const BOUNDS = [
        'score_0_5' => ['0', '5'],
        'ratio' => ['0', '1'],
        'days' => ['0', null],
        'count' => ['0', null],
    ];

    public function component(
        string $componentCode,
        string $unitCode,
        iterable $signals,
    ): ContractorComponentMetric {
        if (
            preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $componentCode) !== 1
            || !array_key_exists($unitCode, self::BOUNDS)
        ) {
            throw new InvalidArgumentException('contractor_component_definition_invalid');
        }

        $sum = '0';
        $sampleSize = 0;
        $eligibleCount = 0;
        [$minimum, $maximum] = self::BOUNDS[$unitCode];

        foreach ($signals as $signal) {
            if (!$signal instanceof ContractorComponentSignal) {
                throw new InvalidArgumentException('contractor_component_signal_invalid');
            }
            if ($signal->eligible) {
                $eligibleCount++;
            }
            if ($signal->value === null) {
                continue;
            }
            if (
                bccomp($signal->value, $minimum, 8) < 0
                || ($maximum !== null && bccomp($signal->value, $maximum, 8) > 0)
            ) {
                throw new InvalidArgumentException('contractor_component_signal_out_of_bounds');
            }
            $sum = bcadd($sum, $signal->value, 8);
            $sampleSize++;
        }

        return new ContractorComponentMetric(
            $componentCode,
            $unitCode,
            $sampleSize === 0 ? null : bcdiv($sum, (string) $sampleSize, 8),
            $sampleSize,
            $eligibleCount,
            $eligibleCount === 0 ? null : bcdiv((string) $sampleSize, (string) $eligibleCount, 8),
        );
    }

    public function serialize(iterable $metrics): array
    {
        $serialized = ['components' => []];
        foreach ($metrics as $metric) {
            if (!$metric instanceof ContractorComponentMetric) {
                throw new InvalidArgumentException('contractor_component_metric_invalid');
            }
            $serialized['components'][] = [
                'component_code' => $metric->componentCode,
                'unit_code' => $metric->unitCode,
                'mean' => $metric->mean,
                'sample_size' => $metric->sampleSize,
                'eligible_count' => $metric->eligibleCount,
                'coverage' => $metric->coverage,
            ];
        }

        return $serialized;
    }
}
