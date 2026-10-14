<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use Illuminate\Database\Eloquent\Builder;

final readonly class AssistantBimIndexQuery
{
    private const ELEMENT_FIELDS = ['id', 'project_id', 'version_id', 'derivative_id', 'express_id', 'global_id', 'category', 'name', 'properties', 'classifications', 'updated_at'];

    public function __construct(private AssistantBimIndexProjection $projection, private AssistantBimIndexPredicate $predicate) {}

    public function readEquipmentBatch(Builder $scope, array $models, array $filters, array $categories): array
    {
        if ($models === [] || $filters['include_quantities'] || ($filters['compare_version_id'] ?? null) !== null
            || ($filters['answer_kind'] ?? 'elements') !== 'elements') {
            throw new \InvalidArgumentException('bim_equipment_batch_requires_bounded_elements');
        }
        $table = $scope->getModel()->getTable();
        $scope->where(function (Builder $query) use ($models, $table): void {
            foreach ($models as $versionId => $model) {
                $query->orWhere(function (Builder $branch) use ($versionId, $model, $table): void {
                    $branch->where($table.'.version_id', $versionId)->where($table.'.project_id', $model['project_id']);
                    if ($model['derivative_id'] !== null) {
                        $branch->where($table.'.derivative_id', $model['derivative_id']);
                    }
                });
            }
        });
        $connection = $scope->getQuery()->getConnection();
        $useReadPdo = ! $scope->getQuery()->useWritePdo;
        $proofScope = (clone $scope)->reorder()->select(array_map(static fn (string $column): string => $table.'.'.$column,
            ['version_id', 'id', 'updated_at', 'source_content_hash', 'source_schema_version', 'category']));
        $sql = 'WITH _assistant_index_scope AS MATERIALIZED ('.$proofScope->toSql()."),
            _assistant_categories AS (
                SELECT version_id, category, COUNT(*) AS element_count,
                    ROW_NUMBER() OVER (PARTITION BY version_id ORDER BY category) AS category_number
                FROM _assistant_index_scope GROUP BY version_id, category)
            SELECT version_id, COUNT(*) AS indexed_count,
                COUNT(*) FILTER (WHERE source_schema_version = 2) AS schema2_count,
                md5(COALESCE(string_agg(md5(concat_ws('|', id::text, updated_at::text, source_content_hash)), '' ORDER BY id), '')) AS fingerprint,
                (SELECT COALESCE(jsonb_agg(jsonb_build_object('category', c.category, 'element_count', c.element_count) ORDER BY c.category), '[]'::jsonb)
                    FROM _assistant_categories c WHERE c.version_id = s.version_id AND c.category_number <= 101) AS category_counts
            FROM _assistant_index_scope s GROUP BY version_id";
        $result = [];
        foreach ($models as $versionId => $model) {
            $result[$versionId] = ['categories' => [], 'elements' => [], 'total' => 0, 'observed_matches' => 0,
                'has_more' => false, 'scan_complete' => true, 'scanned_count' => 0, 'index_fingerprint' => md5(''),
                'schema2_row_count' => 0, 'equipment_counts' => array_fill_keys($filters['equipment_kinds'] ?? [], 0),
                'matching_count_coverage' => 'full_index_query', 'material_summary' => null, 'quantity_totals' => [], 'comparison_rows' => []];
        }
        foreach ($connection->select($sql, $proofScope->getBindings(), $useReadPdo) as $proof) {
            $versionId = (int) $proof->version_id;
            $result[$versionId]['scanned_count'] = (int) $proof->indexed_count;
            $result[$versionId]['schema2_row_count'] = (int) $proof->schema2_count;
            $result[$versionId]['index_fingerprint'] = $proof->fingerprint;
            $result[$versionId]['categories'] = array_map(static fn (array $row): array => ['category' => $row['category'], 'count' => (int) $row['element_count']],
                json_decode($proof->category_counts, true, flags: JSON_THROW_ON_ERROR));
        }
        $this->predicate->apply($scope, $filters, $categories);
        $expression = AssistantBimIndexPredicate::equipmentCountExpression();
        foreach ((clone $scope)->reorder()->select($table.'.version_id')
            ->selectRaw($expression.' AS equipment_count_kind, COUNT(*) AS equipment_count')
            ->groupBy($table.'.version_id')->groupByRaw($expression)->get() as $count) {
            $versionId = (int) $count->version_id;
            $result[$versionId]['total'] += (int) $count->equipment_count;
            if (array_key_exists((string) $count->equipment_count_kind, $result[$versionId]['equipment_counts'])) {
                $result[$versionId]['equipment_counts'][(string) $count->equipment_count_kind] = (int) $count->equipment_count;
            }
        }
        $pageScope = (clone $scope)->reorder()->select(array_map(static fn (string $column): string => $table.'.'.$column, self::ELEMENT_FIELDS))
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY '.$table.'.version_id ORDER BY '.$table.'.id) AS _assistant_row_number');
        $pageSql = 'SELECT * FROM ('.$pageScope->toSql().') _assistant_equipment_page WHERE _assistant_row_number > ? AND _assistant_row_number <= ? ORDER BY version_id, _assistant_row_number';
        foreach ($connection->select($pageSql, [...$pageScope->getBindings(), $filters['offset'], $filters['offset'] + $filters['limit']], $useReadPdo) as $raw) {
            $attributes = (array) $raw;
            unset($attributes['_assistant_row_number']);
            $element = $scope->getModel()->newFromBuilder($attributes, $connection->getName());
            $result[(int) $element->version_id]['elements'][] = $this->pageRow($element, $filters);
        }
        foreach ($result as &$scan) {
            $scan['observed_matches'] = $scan['total'];
            $scan['has_more'] = $filters['offset'] + count($scan['elements']) < $scan['total'];
        }
        unset($scan);

        return $result;
    }

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
        $columns = self::ELEMENT_FIELDS;
        $selected = array_map(static fn (string $column): string => $table.'.'.$column, $columns);
        $page = (clone $scope)->select($selected)->orderBy($table.'.id')->offset($filters['offset'])->limit($filters['limit'])->get()
            ->map(fn ($element): array => $this->pageRow($element, $filters))->all();
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
            'material_summary' => in_array($filters['answer_kind'] ?? 'elements', ['materials', 'materials_and_quantities'], true)
                ? (new AssistantBimMaterialSummary($this->projection))->read($scope, $filters['offset'], $filters['limit']) : null,
            'quantity_totals' => $quantityRows, 'comparison_rows' => $rows];
    }

    private function pageRow(\Illuminate\Database\Eloquent\Model $element, array $filters): array
    {
        $row = $this->projection->element($element->attributesToArray());
        $row['_source_field_identity'] = AssistantSourceReferenceIdentity::key(array_intersect_key($element->getAttributes(), array_flip(array_diff(self::ELEMENT_FIELDS, ['updated_at']))));
        if (! empty($filters['property_name'])) {
            $row['properties'] = array_filter($row['properties'], static fn (string $path): bool => str_contains(mb_strtolower($path), mb_strtolower($filters['property_name'])), ARRAY_FILTER_USE_KEY);
        } elseif (! $filters['include_properties']) {
            $row['properties'] = [];
        }
        if (! $filters['include_quantities']) {
            $row['quantities'] = [];
            $row['quantity_units'] = [];
        }

        return $row;
    }
}
