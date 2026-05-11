<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;

class EstimatePositionOrder
{
    public static function apply(
        EloquentBuilder|QueryBuilder|Relation $query,
        string $column = 'position_number',
        string $direction = 'asc'
    ): EloquentBuilder|QueryBuilder|Relation {
        $direction = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $trimmed = "TRIM(COALESCE({$column}, ''))";

        $driver = null;
        if ($query instanceof QueryBuilder) {
            $driver = $query->getConnection()->getDriverName();
        } elseif ($query instanceof EloquentBuilder) {
            $driver = $query->getModel()->getConnection()->getDriverName();
        } elseif ($query instanceof Relation) {
            $driver = $query->getRelated()->getConnection()->getDriverName();
        }

        if ($driver === 'sqlite') {
            return $query->orderByRaw("NULLIF({$trimmed}, '') {$direction}");
        }

        $isHierarchicalNumber = "{$trimmed} ~ '^[0-9]+([.][0-9]+)*$'";

        return $query
            ->orderByRaw("CASE WHEN {$isHierarchicalNumber} THEN 0 ELSE 1 END {$direction}")
            ->orderByRaw("CASE WHEN {$isHierarchicalNumber} THEN string_to_array({$trimmed}, '.')::int[] END {$direction}")
            ->orderByRaw("NULLIF({$trimmed}, '') {$direction}");
    }
}
