<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\SiteRequests\Enums\SiteRequestTypeEnum;
use Illuminate\Database\Eloquent\Builder;

final class AssistantSiteRequestReportProjection
{
    public function apply(Builder $query, array $columns): Builder
    {
        if (! in_array('request_type', $columns, true)) {
            return $query;
        }
        $table = $query->getModel()->getTable();
        $grammar = $query->getQuery()->getGrammar();
        $column = $grammar->wrap($table.'.request_type');
        $branches = [];
        $bindings = [];
        foreach (['material', 'personnel', 'equipment', 'info', 'issue'] as $legacy) {
            $branches[] = 'WHEN ? THEN ?';
            $bindings[] = $legacy;
            $bindings[] = SiteRequestTypeEnum::fromStoredValue($legacy)->value;
        }
        $query->select(array_map(static fn (string $name): string => $table.'.'.$name,
            array_values(array_diff($columns, ['request_type']))))
            ->selectRaw('CASE '.$column.' '.implode(' ', $branches).' ELSE '.$column.' END AS '.$grammar->wrap('request_type'), $bindings);

        return $query->getModel()->newModelQuery()->fromSub($query, $table);
    }
}
