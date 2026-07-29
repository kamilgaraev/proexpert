<?php

declare(strict_types=1);

namespace App\BusinessModules\ContractorMarketplace\Reporting\Scorecard\DTO;

use InvalidArgumentException;

final readonly class ContractorObjectiveObservationIndex
{
    public function __construct(private array $rows) {}

    public function observations(
        string $sourceReportCode,
        int $profileId,
        int $projectId,
        string $unitCode,
    ): array {
        $rows = $sourceReportCode === 'baseline_schedule_variance'
            ? ($this->rows[$sourceReportCode][$projectId] ?? [])
            : ($this->rows[$sourceReportCode][$profileId][$projectId] ?? []);
        $signals = [];
        $evidence = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('contractor_objective_observation_invalid');
            }
            [$value, $eligible] = $this->signal($sourceReportCode, $unitCode, $row);
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

    private function signal(string $sourceReportCode, string $unitCode, array $row): array
    {
        return match ($sourceReportCode) {
            'baseline_schedule_variance' => [
                match ($unitCode) {
                    'days' => $row['variance_days'] === null
                        ? null
                        : (string) abs((int) $row['variance_days']),
                    'ratio' => (bool) $row['is_critical'] ? '1' : '0',
                    'count' => '1',
                    default => null,
                },
                true,
            ],
            'supply_reliability' => [
                $unitCode === 'ratio' && (bool) $row['eligible']
                    ? (string) (int) $row['otif_numerator']
                    : ($unitCode === 'count' ? '1' : null),
                (bool) $row['eligible'],
            ],
            'quality_defect_flow' => [
                match ($unitCode) {
                    'days' => $row['cycle_days'] === null ? null : (string) $row['cycle_days'],
                    'ratio' => (bool) $row['closed_flag'] ? '1' : '0',
                    'count' => '1',
                    default => null,
                },
                true,
            ],
            'safety_incident_actions' => [
                match ($unitCode) {
                    'days' => $row['closure_days'] === null ? null : (string) $row['closure_days'],
                    'ratio' => (bool) $row['closure_verified'] ? '1' : '0',
                    'count' => '1',
                    default => null,
                },
                true,
            ],
            default => throw new InvalidArgumentException('contractor_objective_source_invalid'),
        };
    }
}
