<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\BudgetEstimates\Services\Finance\FinanceDecimal;

final class AssistantCustomReportProjectedRows
{
    public static function filterAndSort(array $rows, array $section, array $fields): array
    {
        foreach ($section['filters'] as $filter) {
            $rows = array_values(array_filter($rows, static fn (array $row): bool => self::matches($row[$filter['field']] ?? null,
                $filter, $fields[$filter['field']]['type'])));
        }
        if ($section['metrics'] === []) {
            usort($rows, static function (array $left, array $right) use ($section, $fields): int {
                foreach ($section['order_by'] as $order) {
                    $field = $order['field'];
                    $a = $left[$field] ?? null;
                    $b = $right[$field] ?? null;
                    $comparison = $a === null || $b === null
                        ? ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0)
                        : self::compare($a, $b, $fields[$field]['type']);
                    if ($comparison !== 0) {
                        return $order['direction'] === 'desc' ? -$comparison : $comparison;
                    }
                }

                return ($left['id'] ?? 0) <=> ($right['id'] ?? 0);
            });
        }

        return $rows;
    }

    private static function matches(mixed $actual, array $filter, string $type): bool
    {
        $operator = $filter['operator'];
        if ($operator === 'is_null') {
            return $actual === null;
        }
        if ($operator === 'is_not_null') {
            return $actual !== null;
        }
        if ($actual === null) {
            return false;
        }
        if ($operator === 'contains') {
            return mb_stripos((string) $actual, (string) $filter['value']) !== false;
        }
        if ($operator === 'in') {
            foreach ($filter['value'] as $value) {
                if (self::compare($actual, $value, $type) === 0) {
                    return true;
                }
            }

            return false;
        }
        $comparison = self::compare($actual, $filter['value'], $type);

        return match ($operator) {
            'eq' => $comparison === 0, 'neq' => $comparison !== 0,
            'gt' => $comparison > 0, 'gte' => $comparison >= 0,
            'lt' => $comparison < 0, 'lte' => $comparison <= 0,
        };
    }

    private static function compare(mixed $left, mixed $right, string $type): int
    {
        return match ($type) {
            'number' => FinanceDecimal::compare((string) $left, (string) $right),
            'boolean' => (int) (bool) $left <=> (int) (bool) $right,
            'date' => strcmp(substr((string) $left, 0, 10), substr((string) $right, 0, 10)),
            default => strcmp((string) $left, (string) $right),
        };
    }
}
