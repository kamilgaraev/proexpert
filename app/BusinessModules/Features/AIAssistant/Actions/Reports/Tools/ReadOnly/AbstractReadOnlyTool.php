<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Actions\Reports\Tools\ReadOnly;

use App\BusinessModules\Features\AIAssistant\Contracts\AIToolInterface;
use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class AbstractReadOnlyTool implements AIToolInterface
{
    protected const DEFAULT_LIMIT = 10;
    protected const MAX_LIMIT = 30;

    protected function orgTable(string $table, Organization $organization): Builder
    {
        return DB::table($table)->where("{$table}.organization_id", $organization->id);
    }

    protected function withoutDeleted(Builder $query, string $table): Builder
    {
        if ($this->hasColumn($table, 'deleted_at')) {
            $query->whereNull("{$table}.deleted_at");
        }

        return $query;
    }

    protected function hasTable(string $table): bool
    {
        return Schema::hasTable($table);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return Schema::hasColumn($table, $column);
    }

    protected function limit(array $arguments, int $default = self::DEFAULT_LIMIT): int
    {
        $limit = (int) ($arguments['limit'] ?? $default);

        return max(1, min(self::MAX_LIMIT, $limit));
    }

    protected function intArg(array $arguments, string $key): ?int
    {
        if (!isset($arguments[$key]) || $arguments[$key] === '') {
            return null;
        }

        $value = filter_var($arguments[$key], FILTER_VALIDATE_INT);

        return $value === false ? null : (int) $value;
    }

    protected function stringArg(array $arguments, string $key): ?string
    {
        if (!isset($arguments[$key])) {
            return null;
        }

        $value = trim((string) $arguments[$key]);

        return $value === '' ? null : $value;
    }

    protected function applyDateRange(Builder $query, string $column, array $arguments): Builder
    {
        $dateFrom = $this->stringArg($arguments, 'date_from');
        $dateTo = $this->stringArg($arguments, 'date_to');

        if ($dateFrom !== null) {
            $query->whereDate($column, '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $query->whereDate($column, '<=', $dateTo);
        }

        return $query;
    }

    protected function tableUnavailable(string $domain, string $table): array
    {
        return [
            'status' => 'unavailable',
            'domain' => $domain,
            'message' => "Источник данных {$table} недоступен в текущей схеме.",
            'results' => [],
        ];
    }

    protected function statusValue(mixed $status): ?string
    {
        if ($status === null) {
            return null;
        }

        if (is_object($status) && property_exists($status, 'value')) {
            return (string) $status->value;
        }

        return (string) $status;
    }
}
