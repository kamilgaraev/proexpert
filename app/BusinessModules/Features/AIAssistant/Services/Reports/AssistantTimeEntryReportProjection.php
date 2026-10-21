<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use Illuminate\Database\Eloquent\Builder;

final class AssistantTimeEntryReportProjection
{
    public function apply(Builder $query, array $columns): Builder
    {
        $table = $query->getModel()->getTable();
        $grammar = $query->getQuery()->getGrammar();
        $alternatives = [];
        foreach (['net_microseconds' => 3_600_000_000, 'net_minutes' => 60] as $column => $divisor) {
            if (in_array($column, $columns, true)) {
                $value = $grammar->wrap($table.'.'.$column);
                $alternatives[] = 'WHEN '.$value.' IS NOT NULL THEN CAST('.$value.' AS numeric) / '.$divisor;
            }
        }
        if ($alternatives === []) {
            return $query;
        }
        $query->select(array_map(static fn (string $column): string => $table.'.'.$column,
            array_values(array_diff($columns, ['hours_worked']))))
            ->selectRaw('CASE '.implode(' ', $alternatives).' ELSE '.$grammar->wrap($table.'.hours_worked')
                .' END AS '.$grammar->wrap('hours_worked'));

        return $query->getModel()->newModelQuery()->fromSub($query, $table);
    }
}
