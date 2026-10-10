<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

final class AssistantSourceIdentityQueryBuilder
{
    private const MAX_SOURCE_BATCH_ROWS = 10000;

    public function planBatches(array $identities, array $sourceCounts, ?int $batchSize, bool $expectedProjection): array
    {
        $presentTypes = [];
        foreach ($identities as $types) { $presentTypes += $types; }
        $batches = $batchSize === null ? [null] : $this->sourceCountBatches(array_keys($presentTypes), $sourceCounts, $batchSize);
        $planned = [];
        foreach ($batches as $types) {
            if ($expectedProjection && count($types ?? []) === 1
                && ($types === ['organization_reporting'] || ($sourceCounts[$types[0]] ?? 0) > self::MAX_SOURCE_BATCH_ROWS)) {
                foreach ($identities as $entityType => $identity) {
                    if (isset($identity[$types[0]])) { $planned[] = ['types' => $types, 'entity' => $entityType]; }
                }
            } else { $planned[] = ['types' => $types, 'entity' => null]; }
        }

        return $planned;
    }

    public function identityBranch(Builder $entities, string $sourceType, string $entityType, ?QueryBuilder $identitySources, string $table, bool $expectedProjection = false): QueryBuilder
    {
        $key = $entities->getModel()->getQualifiedKeyName();
        if ($identitySources === null) {
            return $entities->select([])->selectRaw('? AS source_type, ? AS entity_type, CAST('.$key.' AS TEXT) AS entity_id', [$sourceType, $entityType])->toBase();
        }
        if ($expectedProjection) {
            return $this->expectedIdentityBranch($entities, $sourceType, $entityType, $identitySources, $table);
        }
        $sources = (clone $identitySources)->where($table.'.source_type', $sourceType)->where($table.'.entity_type', $entityType)
            ->selectRaw($table.'.id AS admitted_source_id, '.$table.'.entity_id AS admitted_entity_id');
        $grammar = $entities->getQuery()->getGrammar();

        if (in_array($entityType, ['approved_estimate_resource_price', 'approved_estimate_norm_resource'], true)) {
            $entityId = $grammar->wrap($table.'.entity_id');
            $sources->selectRaw('CASE WHEN pg_input_is_valid('.$entityId.", 'bigint') THEN CASE WHEN CAST(".$entityId.'::bigint AS text) COLLATE "C" = '.$entityId.' COLLATE "C" THEN '.$entityId.'::bigint END END AS admitted_entity_key');
            $keys = DB::query()->fromSub(clone $sources, 'native_candidate_ids')->selectRaw('ARRAY_AGG(native_candidate_ids.admitted_entity_key)');
            $entities->whereRaw($grammar->wrap($key).' = ANY(CAST(('.$keys->toSql().') AS bigint[]))', $keys->getBindings());

            return $entities->distinct()->select('eligible_identity_source.admitted_source_id AS source_id')
                ->joinSub($sources, 'eligible_identity_source', static fn (\Illuminate\Database\Query\JoinClause $join) => $join
                    ->on('eligible_identity_source.admitted_entity_key', '=', $key))->toBase();
        }
        if (! in_array($entityType, ['design_ifc_model_element', 'approved_estimate_norm', 'approved_construction_resource'], true)) { $entities->distinct(); }
        return $entities->select('eligible_identity_source.admitted_source_id AS source_id')
            ->joinSub($sources, 'eligible_identity_source', static fn (\Illuminate\Database\Query\JoinClause $join) => $join
                ->whereRaw($grammar->wrap('eligible_identity_source.admitted_entity_id').' COLLATE "C" = CAST('.$grammar->wrap($key).' AS TEXT)'))->toBase();
    }

    private function expectedIdentityBranch(Builder $entities, string $sourceType, string $entityType, QueryBuilder $identitySources, string $table): QueryBuilder
    {
        $key = $entities->getModel()->getQualifiedKeyName();
        $grammar = $entities->getQuery()->getGrammar();
        $sources = (clone $identitySources)->where($table.'.source_type', $sourceType)->where($table.'.entity_type', $entityType);
        $columns = array_map(static fn (string $column): string => 'eligible_identity_source.'.$column,
            ['id', 'organization_id', 'project_id', 'generation', 'identity_project_id', 'identity_part_key', 'source_type', 'entity_type', 'entity_id', 'checksum', 'pending_since', 'created_at', 'updated_at']);
        if (in_array($entityType, ['approved_estimate_resource_price', 'approved_estimate_norm_resource', 'core_normative_resource', 'estimate_template', 'estimate_library_item', 'normative_rate', 'estimate_catalog_item', 'estimate_item', 'estimate_item_resource'], true)) {
            $entityId = $grammar->wrap($table.'.entity_id');
            $sources->selectRaw($grammar->wrap($table).'.*, CASE WHEN pg_input_is_valid('.$entityId.", 'bigint') THEN CASE WHEN CAST(".$entityId.'::bigint AS text) COLLATE "C" = '.$entityId.' COLLATE "C" THEN '.$entityId.'::bigint END END AS admitted_entity_key');
            if (in_array($entityType, ['approved_estimate_resource_price', 'approved_estimate_norm_resource'], true)) {
                $keys = DB::query()->fromSub(clone $sources, 'native_candidate_ids')->selectRaw('ARRAY_AGG(native_candidate_ids.admitted_entity_key)');
                $entities->whereRaw($grammar->wrap($key).' = ANY(CAST(('.$keys->toSql().') AS bigint[]))', $keys->getBindings());
            }

            return $entities->select($columns)->joinSub($sources, 'eligible_identity_source', static fn (\Illuminate\Database\Query\JoinClause $join) => $join
                ->on('eligible_identity_source.admitted_entity_key', '=', $key))->toBase();
        }
        if (! in_array($entityType, ['design_ifc_model_element', 'approved_estimate_norm', 'approved_construction_resource'], true)) { $entities->distinct(); }
        $native = $entities->select([])->selectRaw($grammar->wrap($key).' AS admitted_native_key')->toBase();
        $sources->selectRaw($grammar->wrap($table).'.*, '.$grammar->wrap($table.'.entity_id').' AS admitted_entity_id');

        return DB::query()->fromSub($native, 'eligible_native_identity')->joinSub($sources, 'eligible_identity_source', static fn (\Illuminate\Database\Query\JoinClause $join) => $join
            ->whereRaw($grammar->wrap('eligible_identity_source.admitted_entity_id').' COLLATE "C" = CAST('.$grammar->wrap('eligible_native_identity.admitted_native_key').' AS TEXT)'))->select($columns);
    }

    private function sourceCountBatches(array $sourceTypes, array $sourceCounts, int $batchSize): array
    {
        $largeTypes = array_values(array_intersect($sourceTypes,
            array_keys(array_filter($sourceCounts, static fn (int $count): bool => $count > self::MAX_SOURCE_BATCH_ROWS))));
        $batches = array_map(static fn (string $type): array => [$type], $largeTypes);
        $batch = [];
        $rows = 0;
        foreach (array_values(array_diff($sourceTypes, $largeTypes)) as $type) {
            $count = $sourceCounts[$type] ?? 0;
            if ($batch !== [] && (count($batch) >= $batchSize || $rows + $count > self::MAX_SOURCE_BATCH_ROWS)) {
                $batches[] = $batch;
                $batch = [];
                $rows = 0;
            }
            $batch[] = $type;
            $rows += $count;
        }
        if ($batch !== [] || $batches === []) { $batches[] = $batch; }

        return $batches;
    }

    public function discoverIdentities(Builder $query, callable $finish, ?array &$sourceCounts = null): array
    {
        $table = $query->getModel()->getTable();
        $identityQuery = $query->toBase()->cloneWithout(['columns', 'orders', 'limit', 'offset'])
            ->select([$table.'.source_type', $table.'.entity_type']);
        if ($sourceCounts === null) {
            $identityQuery->distinct();
        } else {
            $identityQuery->groupBy([$table.'.source_type', $table.'.entity_type'])->selectRaw('COUNT(*) AS identity_count');
        }
        $identities = [];
        foreach ($finish($query, $identityQuery)->get() as $identity) {
            $identities[(string) $identity->entity_type][(string) $identity->source_type] = true;
            if ($sourceCounts !== null) {
                $type = (string) $identity->source_type;
                $sourceCounts[$type] = ($sourceCounts[$type] ?? 0) + (int) $identity->identity_count;
            }
        }

        return $identities;
    }

    public function assertExpectedSourceQuery(Builder $query): void
    {
        if (! $query->getModel() instanceof RagExpectedSource
            || $query->getModel()->getTable() !== 'ai_rag_expected_sources'
            || $query->getQuery()->from !== 'ai_rag_expected_sources') {
            throw new \InvalidArgumentException('assistant_expected_projection_model_required');
        }
    }

}
