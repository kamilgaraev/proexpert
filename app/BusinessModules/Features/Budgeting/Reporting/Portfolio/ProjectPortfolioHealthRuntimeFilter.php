<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Budgeting\Reporting\Portfolio;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;

final class ProjectPortfolioHealthRuntimeFilter
{
    private const RISK_LEVELS = [
        'low',
        'medium',
        'high',
        'critical',
    ];

    public function projects(array $projects, mixed $managerIds, mixed $statuses): array
    {
        $managerIds = $this->positiveIds($managerIds);
        $statuses = $this->strings($statuses);
        $availableManagerIds = [];
        $availableStatuses = [];

        foreach ($projects as $project) {
            if (! is_array($project)
                || ! is_int($project['id'] ?? null)
                || $project['id'] < 1
                || ! is_string($project['status'] ?? null)
                || trim($project['status']) === ''
                || ! is_array($project['manager_ids'] ?? null)
                || ! array_is_list($project['manager_ids'])) {
                $this->sourceUnavailable();
            }
            $availableStatuses[$project['status']] = true;
            foreach ($project['manager_ids'] as $managerId) {
                if (! is_int($managerId) || $managerId < 1) {
                    $this->sourceUnavailable();
                }
                $availableManagerIds[$managerId] = true;
            }
        }

        foreach ($managerIds as $managerId) {
            if (! isset($availableManagerIds[$managerId])) {
                $this->valueNotFound();
            }
        }
        foreach ($statuses as $status) {
            if (! isset($availableStatuses[$status])) {
                $this->valueNotFound();
            }
        }

        $allowedManagers = array_fill_keys($managerIds, true);
        $allowedStatuses = array_fill_keys($statuses, true);

        return array_filter($projects, static function (array $project) use ($allowedManagers, $allowedStatuses): bool {
            if ($allowedStatuses !== [] && ! isset($allowedStatuses[$project['status']])) {
                return false;
            }
            if ($allowedManagers === []) {
                return true;
            }
            foreach ($project['manager_ids'] as $managerId) {
                if (isset($allowedManagers[$managerId])) {
                    return true;
                }
            }

            return false;
        });
    }

    public function riskRowIndexes(array $rowRiskLevels, mixed $riskLevels): array
    {
        $riskLevels = $this->strings($riskLevels);
        if ($riskLevels === []) {
            return array_keys($rowRiskLevels);
        }
        foreach ($riskLevels as $riskLevel) {
            if (! in_array($riskLevel, self::RISK_LEVELS, true)) {
                $this->valueNotFound();
            }
        }
        $available = [];
        foreach ($rowRiskLevels as $riskLevel) {
            if (! is_string($riskLevel) || ! in_array($riskLevel, self::RISK_LEVELS, true)) {
                $this->sourceUnavailable();
            }
            $available[$riskLevel] = true;
        }
        foreach ($riskLevels as $riskLevel) {
            if (! isset($available[$riskLevel])) {
                $this->valueNotFound();
            }
        }
        $allowed = array_fill_keys($riskLevels, true);

        return array_keys(array_filter(
            $rowRiskLevels,
            static fn (string $riskLevel): bool => isset($allowed[$riskLevel]),
        ));
    }

    private function positiveIds(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->valueNotFound();
        }
        $ids = [];
        foreach ($value as $id) {
            if ((! is_int($id) && (! is_string($id) || preg_match('/^[1-9][0-9]*$/D', $id) !== 1))
                || (int) $id < 1) {
                $this->valueNotFound();
            }
            $ids[(int) $id] = (int) $id;
        }
        ksort($ids, SORT_NUMERIC);

        return array_values($ids);
    }

    private function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->valueNotFound();
        }
        $strings = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                $this->valueNotFound();
            }
            $normalized = trim($item);
            $strings[$normalized] = $normalized;
        }
        ksort($strings, SORT_STRING);

        return array_values($strings);
    }

    private function valueNotFound(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_VALUE_NOT_FOUND);
    }

    private function sourceUnavailable(): never
    {
        throw ReportContractException::fromCode(ReportErrorCode::REPORT_SOURCE_UNAVAILABLE);
    }
}
