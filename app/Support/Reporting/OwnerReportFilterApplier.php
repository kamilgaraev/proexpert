<?php

declare(strict_types=1);

namespace App\Support\Reporting;

use App\BusinessModules\Core\Reporting\Application\Errors\ReportContractException;
use App\BusinessModules\Core\Reporting\Application\Errors\ReportErrorCode;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportFilterSet;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;

final readonly class OwnerReportFilterApplier
{
    public function apply(Builder $query, ReportFilterSet $filters, array $columns): Builder
    {
        foreach ($filters->values as $filter => $condition) {
            $mapping = $columns[$filter] ?? null;
            $invertBoolean = is_array($mapping) && ($mapping['invert_boolean'] ?? false) === true;
            $column = is_array($mapping) ? ($mapping['column'] ?? null) : $mapping;
            if ((! is_string($column) && ! $column instanceof Expression) || ! is_array($condition)) {
                throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED);
            }
            $operator = $condition['operator'] ?? null;
            $value = $condition['value'] ?? null;
            if ($invertBoolean && is_bool($value)) {
                $value = ! $value;
            }

            match ($operator) {
                'eq' => $query->where($column, '=', $value),
                'neq' => $query->where($column, '<>', $value),
                'in' => $query->whereIn($column, $value),
                'not_in' => $query->whereNotIn($column, $value),
                'gt' => $query->where($column, '>', $value),
                'gte' => $query->where($column, '>=', $value),
                'lt' => $query->where($column, '<', $value),
                'lte' => $query->where($column, '<=', $value),
                'between' => $query->whereBetween($column, $value),
                'contains' => $query->whereLike(
                    $column,
                    '%'.$this->escaped((string) $value).'%',
                    caseSensitive: true,
                ),
                default => throw ReportContractException::fromCode(ReportErrorCode::REPORT_FILTER_UNSUPPORTED),
            };
        }

        return $query;
    }

    public function only(ReportFilterSet $filters, array $names): ReportFilterSet
    {
        return new ReportFilterSet(array_intersect_key($filters->values, array_flip($names)));
    }

    private function escaped(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
