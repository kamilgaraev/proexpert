<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use Illuminate\Database\Eloquent\Builder;

final class AssistantSafetyIncidentReportProjection
{
    public function apply(Builder $incidents, array $columns): Builder
    {
        $table = $incidents->getModel()->getTable();
        $incidents->select(array_map(static fn (string $column): string => $table.'.'.$column, array_values(array_diff($columns, ['occurred_at']))));
        $date = $incidents->getQuery()->getGrammar()->wrap($table.'.occurred_at');
        $incidents->selectRaw('('.$date.' AT TIME ZONE ? AT TIME ZONE ?) AS occurred_at',
            [(string) config('app.timezone', 'UTC'), 'Europe/Moscow']);

        return $incidents->getModel()->newModelQuery()->fromSub($incidents, $table);
    }
}
