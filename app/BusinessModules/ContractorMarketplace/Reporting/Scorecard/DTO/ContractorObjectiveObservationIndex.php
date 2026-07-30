<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ContractorObjectiveObservationIndex
{
    public function __construct(
        private array $rows,
        private array $categoriesByProfile = [],
        private array $profileOrganizationById = [],
    ) {}

    public function categoryIds(int $profileId): array
    {
        return array_map('intval', array_keys($this->categoriesByProfile[$profileId] ?? []));
    }

    public function profileOrganizationId(int $profileId): ?int
    {
        $organizationId = $this->profileOrganizationById[$profileId] ?? null;

        return is_int($organizationId) ? $organizationId : null;
    }

    public function observations(
        string $sourceReportCode,
        int $profileId,
        int $projectId,
        string $sourceMetric,
        string $unitCode,
        string $cohortKey,
        string $cohortPeriod,
    ): array {
        $rows = $this->rows[$sourceReportCode][$profileId][$projectId] ?? [];
        $signals = [];
        $evidence = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('contractor_objective_observation_invalid');
            }
            if (! hash_equals($this->cohortKey($row, $cohortPeriod), $cohortKey)) {
                continue;
            }
            [$value, $eligible] = $this->signal($sourceReportCode, $sourceMetric, $unitCode, $row);
            $signals[] = new ContractorComponentSignal($value, $eligible);
            $evidence[] = [
                'source_report_code' => $sourceReportCode,
                'source_row_id' => (int) $row['id'],
                'source_row_key' => (string) $row['row_key'],
                'source_snapshot_id' => (string) $row['snapshot_id'],
            ];
        }

        return ['signals' => $signals, 'evidence' => $evidence];
    }

    public function profileProjects(?string $cohortKey = null, ?string $cohortPeriod = null): array
    {
        $dimensions = [];
        foreach ($this->rows as $sources) {
            foreach ($sources as $profileId => $projects) {
                foreach ($projects as $projectId => $rows) {
                    foreach ($rows as $row) {
                        if (
                            $cohortKey !== null
                            && $cohortPeriod !== null
                            && ! hash_equals($this->cohortKey($row, $cohortPeriod), $cohortKey)
                        ) {
                            continue;
                        }
                        $dimensions[(int) $profileId][(int) $projectId] = true;
                        break;
                    }
                }
            }
        }

        return $dimensions;
    }

    public function profileProjectCohorts(string $cohortPeriod): array
    {
        $dimensions = [];
        foreach ($this->rows as $sources) {
            foreach ($sources as $profileId => $projects) {
                foreach ($projects as $projectId => $rows) {
                    foreach ($rows as $row) {
                        if (! is_array($row)) {
                            throw new InvalidArgumentException('contractor_objective_observation_invalid');
                        }
                        $dimensions[(int) $profileId][(int) $projectId][$this->cohortKey(
                            $row,
                            $cohortPeriod,
                        )] = true;
                    }
                }
            }
        }

        return $dimensions;
    }

    private function cohortKey(array $row, string $period): string
    {
        if (is_string($row['_cohort_key'] ?? null) && $row['_cohort_key'] !== '') {
            return $row['_cohort_key'];
        }
        if (! is_string($row['_observed_at'] ?? null)) {
            throw new InvalidArgumentException('contractor_objective_observation_period_missing');
        }
        $date = CarbonImmutable::parse($row['_observed_at']);

        return match ($period) {
            'month' => $date->format('Y-m'),
            'quarter' => $date->year.'-Q'.$date->quarter,
            'year' => $date->format('Y'),
            default => throw new InvalidArgumentException('contractor_objective_observation_period_invalid'),
        };
    }

    private function signal(
        string $sourceReportCode,
        string $sourceMetric,
        string $unitCode,
        array $row,
    ): array {
        return match ($sourceReportCode) {
            'baseline_schedule_variance' => [
                match ($sourceMetric) {
                    'variance_days' => $row['variance_days'] === null
                        ? null
                        : (string) abs((int) $row['variance_days']),
                    'critical_flag' => (bool) $row['is_critical'] ? '1' : '0',
                    'task_count' => '1',
                    default => null,
                },
                true,
            ],
            'supply_reliability' => [
                $sourceMetric === 'otif' && (bool) $row['eligible']
                    ? (string) (int) $row['otif_numerator']
                    : ($sourceMetric === 'delivery_count' ? '1' : null),
                (bool) $row['eligible'],
            ],
            'quality_defect_flow' => [
                match ($sourceMetric) {
                    'cycle_days' => $row['cycle_days'] === null ? null : (string) $row['cycle_days'],
                    'closed_flag' => (bool) $row['closed_flag'] ? '1' : '0',
                    'defect_count' => '1',
                    default => null,
                },
                true,
            ],
            'safety_incident_actions' => [
                match ($sourceMetric) {
                    'closure_days' => $row['closure_days'] === null ? null : (string) $row['closure_days'],
                    'closure_verified' => (bool) $row['closure_verified'] ? '1' : '0',
                    'action_count' => '1',
                    default => null,
                },
                true,
            ],
            default => throw new InvalidArgumentException('contractor_objective_source_invalid'),
        };
    }
}
