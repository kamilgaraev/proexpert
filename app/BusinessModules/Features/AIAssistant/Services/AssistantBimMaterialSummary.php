<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final readonly class AssistantBimMaterialSummary
{
    public function __construct(private AssistantBimIndexProjection $projection) {}

    public function read(Builder $scope, int $offset, int $limit): array
    {
        $table = $scope->getModel()->getTable();
        $values = (clone $scope)->reorder()->select([])->join(DB::raw("LATERAL jsonb_array_elements({$table}.source_materials) AS material_entry(value)"),
            static fn (JoinClause $join): JoinClause => $join->whereRaw('TRUE'))
            ->whereRaw("jsonb_typeof(material_entry.value) = 'string'")
            ->selectRaw("material_entry.value #>> '{}' AS raw_name")->toBase();
        $all = DB::query()->fromSub($values, 'material_values')->select('raw_name')->distinct();
        $rawTotal = DB::query()->fromSub(clone $all, 'all_material_names')->count();
        $safe = (clone $all)->whereRaw('char_length(raw_name) BETWEEN 1 AND 1024')
            ->whereRaw("raw_name !~* 'https?://|[[:alnum:].+_-]+@[[:alnum:]._-]+[.][[:alpha:]]{2,}|(token|secret|password)[[:space:]]*[:=]'");
        $rows = DB::query()->fromSub($safe, 'safe_material_names')->selectRaw('raw_name, COUNT(*) OVER () AS distinct_total')
            ->orderBy('raw_name')->limit(4097)->get();
        $safeTotal = (int) ($rows->first()?->distinct_total ?? 0);
        $names = [];
        $invalidLabels = false;
        foreach ($rows->take(4096) as $row) {
            $label = $this->projection->materialLabel($row->raw_name);
            if ($label !== null && trim($label) !== '') { $names[$label] = $label; } else { $invalidLabels = true; }
        }
        $names = array_values($names);
        sort($names);
        $complete = $safeTotal <= 4096 && $rawTotal === $safeTotal && ! $invalidLabels;

        $page = array_slice($names, $offset, $limit);

        return ['names' => $page, 'total' => $complete ? count($names) : null, 'offset' => $offset,
            'next_offset' => $complete && $offset + count($page) < count($names) ? $offset + count($page) : null,
            'coverage' => $complete ? 'full_matching_index' : 'partial', 'truncated' => ! $complete || $offset + count($page) < count($names)];
    }
}
