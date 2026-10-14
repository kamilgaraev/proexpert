<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantBimIndexQuery
{
    public function __construct(private AssistantBimIndexProjection $projection, private AssistantBimIndexPredicate $predicate) {}

    public function read(Builder $scope, array $filters, array $categories, array $units, bool $retainRows = false): array
    {
        $table = $scope->getModel()->getTable();
        $proofScope = (clone $scope)->reorder()->select(array_map(static fn (string $column): string => $table.'.'.$column,
            ['id', 'updated_at', 'source_content_hash', 'source_schema_version', 'category']));
        $proofSql = 'WITH _assistant_index_scope AS MATERIALIZED ('.$proofScope->toSql().")
            SELECT COUNT(*) AS indexed_count, COUNT(*) FILTER (WHERE source_schema_version = 2) AS schema2_count,
                md5(COALESCE(string_agg(md5(concat_ws('|', id::text, updated_at::text, source_content_hash)), '' ORDER BY id), '')) AS fingerprint,
                (SELECT COALESCE(jsonb_agg(to_jsonb(categories)), '[]'::jsonb) FROM
                    (SELECT category, COUNT(*) AS element_count FROM _assistant_index_scope
                        GROUP BY category ORDER BY category LIMIT 101) categories) AS category_counts
            FROM _assistant_index_scope";
        $proof = $scope->getQuery()->getConnection()->selectOne($proofSql, $proofScope->getBindings(), ! $proofScope->getQuery()->useWritePdo);
        $indexedCount = (int) ($proof?->indexed_count ?? 0);
        $schema2Count = (int) ($proof?->schema2_count ?? 0);
        $fingerprint = $proof?->fingerprint;
        $categoriesResult = array_map(static fn (array $row): array => ['category' => $row['category'], 'count' => (int) $row['element_count']],
            json_decode($proof?->category_counts ?? '[]', true, flags: JSON_THROW_ON_ERROR));
        $this->predicate->apply($scope, $filters, $categories);
        $equipmentCounts = [];
        if (! empty($filters['equipment_kinds'])) {
            $matchedCount = 0;
            $equipmentCounts = array_fill_keys($filters['equipment_kinds'], 0);
            $expression = AssistantBimIndexPredicate::equipmentCountExpression();
            foreach ((clone $scope)->reorder()->select([])->selectRaw($expression.' AS equipment_count_kind, COUNT(*) AS equipment_count')
                ->groupByRaw($expression)->get() as $row) {
                $matchedCount += (int) $row->equipment_count;
                if (array_key_exists((string) $row->equipment_count_kind, $equipmentCounts)) {
                    $equipmentCounts[(string) $row->equipment_count_kind] = (int) $row->equipment_count;
                }
            }
        } else { $matchedCount = (clone $scope)->count(); }
        $columns = ['id', 'project_id', 'version_id', 'derivative_id', 'express_id', 'global_id', 'category', 'name', 'properties', 'classifications', 'updated_at'];
        $selected = array_map(static fn (string $column): string => $table.'.'.$column, $columns);
        $page = (clone $scope)->select($selected)->orderBy($table.'.id')->offset($filters['offset'])->limit($filters['limit'])->get()
            ->map(function ($element) use ($columns): array {
                $row = $this->projection->element($element->attributesToArray());
                $row['_source_field_identity'] = AssistantSourceReferenceIdentity::key(array_intersect_key($element->getAttributes(), array_flip(array_diff($columns, ['updated_at']))));
                return $row;
            })->all();
        foreach ($page as &$row) {
            if (! empty($filters['property_name'])) {
                $row['properties'] = array_filter($row['properties'], static fn (string $path): bool => str_contains(mb_strtolower($path), mb_strtolower($filters['property_name'])), ARRAY_FILTER_USE_KEY);
            } elseif (! $filters['include_properties']) {
                $row['properties'] = [];
            }
            if (! $filters['include_quantities']) {
                $row['quantities'] = [];
                $row['quantity_units'] = [];
            }
        }
        unset($row);
        $rows = [];
        $quantities = [];
        $scanned = 0;
        $complete = true;
        $deadline = microtime(true) + ($retainRows ? 4.0 : 8.0);
        $retainedBytes = 0;
        $stream = $retainRows || $filters['include_quantities'] ? $scope->select($selected)->lazyById(500, $table.'.id', 'id') : [];
        foreach ($stream as $element) {
            if ($scanned >= 100000 || microtime(true) > $deadline) {
                $complete = false;
                break;
            }
            $row = $this->projection->element($element->attributesToArray());
            $scanned++;
            if ($retainRows) {
                $retainedBytes += strlen(json_encode($row, JSON_THROW_ON_ERROR));
                if ($retainedBytes > 32 * 1024 * 1024) {
                    $complete = false;
                    break;
                }
                $rows[] = $row;
            }
            if ($filters['include_quantities']) {
                foreach ($this->projection->quantities($row, $units) as $quantity) {
                    if (! empty($filters['quantity_name']) && ! str_contains(mb_strtolower($quantity['name']), mb_strtolower($filters['quantity_name']))) {
                        continue;
                    }
                    $key = json_encode([$quantity['name'], $quantity['dimension'], $quantity['unit']], JSON_THROW_ON_ERROR);
                    if (! isset($quantities[$key])) {
                        if (count($quantities) >= 100) {
                            continue;
                        }
                        $quantities[$key] = $quantity + ['elements' => 0, 'sum' => 0.0];
                        unset($quantities[$key]['value']);
                    }
                    $quantities[$key]['elements']++;
                    $quantities[$key]['sum'] += $quantity['value'];
                }
            }
        }
        $quantityRows = array_values($quantities);
        foreach ($quantityRows as &$quantity) {
            $quantity['coverage'] = $complete ? 'full_matching_index' : 'partial_scan';
            if ($quantity['unit'] === null || ! $complete || ! is_finite($quantity['sum'])) {
                $quantity['sum'] = null;
            }
        }
        unset($quantity);

        return ['categories' => $categoriesResult,
            'elements' => $page, 'total' => $matchedCount, 'observed_matches' => $matchedCount,
            'has_more' => $filters['offset'] + count($page) < $matchedCount,
            'scan_complete' => $complete, 'scanned_count' => $indexedCount, 'index_fingerprint' => $fingerprint,
            'schema2_row_count' => $schema2Count, 'equipment_counts' => $equipmentCounts,
            'matching_count_coverage' => 'full_index_query',
            'material_summary' => ($filters['answer_kind'] ?? 'elements') === 'materials'
                ? (new AssistantBimMaterialSummary($this->projection))->read($scope, $filters['offset'], $filters['limit']) : null,
            'quantity_totals' => $quantityRows, 'comparison_rows' => $rows];
    }
}
