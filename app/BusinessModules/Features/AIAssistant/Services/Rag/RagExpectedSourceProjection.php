<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RagExpectedSourceProjection
{
    public const MAX_PRUNE_ROWS = 1000000;

    private const DELETE_BATCH_SIZE = 1000;

    private const ORPHAN_GRACE_HOURS = 3;

    public function __construct(private readonly RagIndexer $indexer) {}

    public function stage(int $organizationId, string $sourceType, string $generation, array $batch, ?array $identities = null): void
    {
        $timestamp = now();
        $rows = [];
        foreach ($batch as $chunk) {
            if ($chunk->organizationId !== $organizationId || $chunk->sourceType !== $sourceType) {
                throw new RuntimeException('rag_coverage_collector_scope_mismatch');
            }
        }

        $identities ??= $this->indexer->coverageIdentities($batch);
        foreach ($batch as $batchKey => $chunk) {
            $identity = $identities[$batchKey] ?? throw new RuntimeException('rag_coverage_identity_missing');
            $changedAt = $chunk->updatedAt === null ? $timestamp : Carbon::instance($chunk->updatedAt);
            $identityKey = json_encode([$identity['identity_project_id'], $identity['identity_part_key'], $identity['entity_type'], $identity['entity_id']], JSON_THROW_ON_ERROR);
            $rows[$identityKey] = $identity + ['generation' => $generation, 'pending_since' => $changedAt->min($timestamp),
                'created_at' => $timestamp, 'updated_at' => $timestamp];
        }
        RagExpectedSource::query()->upsert(array_values($rows),
            ['organization_id', 'generation', 'identity_project_id', 'source_type', 'entity_type', 'entity_id', 'identity_part_key'],
            ['project_id', 'checksum', 'pending_since', 'updated_at']);
    }

    public function actorCounts(int $organizationId, User $actor, AssistantDataAccessPolicy $policy, array $types, string $generation, ?callable $checkpoint = null, ?array &$proof = null, bool $collectProof = true): array
    {
        $proof = $collectProof ? [] : null;
        if ($checkpoint !== null) { $checkpoint(); }
        $expected = RagExpectedSource::query()->where('ai_rag_expected_sources.organization_id', $organizationId)
            ->whereRaw('ai_rag_expected_sources.generation = (SELECT CAST(? AS uuid))', [$generation])->whereIn('ai_rag_expected_sources.source_type', $types);
        if ($collectProof) {
            $projects = $policy->entityQuery($actor, $organizationId, 'project');
            $expected->where(static function (Builder $scope) use ($projects): void {
                $scope->where('ai_rag_expected_sources.source_type', 'file_document')->orWhereNull('ai_rag_expected_sources.project_id');
                if ($projects !== null) {
                    $scope->orWhereIn('ai_rag_expected_sources.project_id', $projects->select('projects.id'));
                }
            });
        }
        $matched = $collectProof
            ? 'matched_sources.id IS NOT NULL AND EXISTS (SELECT 1 FROM ai_rag_chunks matched_chunks WHERE matched_chunks.source_id = matched_sources.id AND matched_chunks.organization_id = expected.organization_id AND matched_chunks.project_id IS NOT DISTINCT FROM expected.project_id AND matched_chunks.embedding IS NOT NULL)'
            : 'matched_sources.id IS NOT NULL AND COALESCE(matched_sources.indexed_chunk_count, 0) > 0';
        $proofColumns = array_map(static fn (string $column): string => "COALESCE(CAST(expected.".$column." AS TEXT), '')",
            ['source_type', 'entity_type', 'entity_id', 'project_id', 'identity_project_id', 'identity_part_key', 'checksum']);
        $counts = [];
        $aggregate = static function (\Illuminate\Database\Query\Builder $visible, ?array $batchTypes = null, ?array $entityTypes = null) use ($matched, $collectProof, $proofColumns, $organizationId, $types): \Illuminate\Database\Query\Builder {
            $query = DB::query()->fromSub($visible, 'expected');
            $joinIdentity = static function (JoinClause $join): void {
                foreach (['organization_id', 'identity_project_id', 'identity_part_key', 'source_type', 'entity_type', 'entity_id', 'checksum'] as $column) {
                    $join->on('matched_sources.'.$column, '=', 'expected.'.$column);
                }
                $join->whereRaw('matched_sources.project_id IS NOT DISTINCT FROM expected.project_id');
            };
            if ($collectProof) {
                $query->leftJoin('ai_rag_sources as matched_sources', $joinIdentity);
            } else {
                $matchedSources = DB::table('ai_rag_sources')->leftJoin('ai_rag_status_sources as matched_status_cache', static function (JoinClause $join): void {
                    $join->on('matched_status_cache.id', '=', 'ai_rag_sources.id')
                        ->on('matched_status_cache.organization_id', '=', 'ai_rag_sources.organization_id')
                        ->whereRaw('matched_status_cache.project_id IS NOT DISTINCT FROM ai_rag_sources.project_id');
                })
                    ->where('ai_rag_sources.organization_id', $organizationId)->whereIn('ai_rag_sources.source_type', $batchTypes ?? $types)
                    ->select(['ai_rag_sources.id', 'ai_rag_sources.organization_id', 'ai_rag_sources.project_id', 'ai_rag_sources.identity_project_id', 'ai_rag_sources.identity_part_key',
                        'ai_rag_sources.source_type', 'ai_rag_sources.entity_type', 'ai_rag_sources.entity_id', 'ai_rag_sources.checksum', 'matched_status_cache.indexed_chunk_count']);
                if ($entityTypes !== null) { $matchedSources->whereIn('ai_rag_sources.entity_type', $entityTypes); }
                $materialized = DB::query()->fromRaw('(WITH matched_source_cache AS MATERIALIZED ('.$matchedSources->toSql().') SELECT * FROM matched_source_cache) AS matched_source_cache', $matchedSources->getBindings())
                    ->select('matched_source_cache.*');
                $query->leftJoinSub($materialized, 'matched_sources', $joinIdentity);
            }
            $query->groupBy('expected.source_type')
                ->selectRaw('expected.source_type, COUNT(*) AS expected_count, pg_catalog.SUM(CASE WHEN '.$matched.' THEN 1 ELSE 0 END) AS indexed_count, MIN(CASE WHEN NOT ('.$matched.') THEN expected.pending_since END) AS pending_since');
            if ($collectProof) {
                $query->selectRaw('jsonb_object_agg(CAST(expected.id AS TEXT), jsonb_build_array('.implode(', ', $proofColumns).')) AS identity_proof');
            }

            return $query;
        };
        if (! $collectProof) {
            $batches = $policy->aggregateExpectedSourceIdentityBatches($expected, $actor, $organizationId,
                ['ai_rag_expected_sources.*'], $aggregate, $checkpoint);
        } else {
            $batches = [];
            foreach ($policy->sourceIdentityQueries($expected, $actor, $organizationId, $checkpoint, true) as $branch) {
                $batches[] = $aggregate($branch->select('ai_rag_expected_sources.*')->toBase());
            }
        }
        foreach ($batches as $batch) {
            if ($checkpoint !== null) { $checkpoint(); }
            $rows = $batch->get();
            foreach ($rows as $row) {
                if ($collectProof) {
                    $proof += json_decode((string) $row->identity_proof, true, 512, JSON_THROW_ON_ERROR);
                    unset($row->identity_proof);
                }
                $previous = $counts[$row->source_type] ?? null;
                if ($previous !== null) {
                    $row->expected_count = (int) $row->expected_count + (int) $previous->expected_count;
                    $row->indexed_count = (int) $row->indexed_count + (int) $previous->indexed_count;
                    if ($previous->pending_since !== null && ($row->pending_since === null || $previous->pending_since < $row->pending_since)) { $row->pending_since = $previous->pending_since; }
                }
                $counts[$row->source_type] = $row;
            }
        }
        return $counts;
    }

    public function prune(int $organizationId, string $generation): void
    {
        $lease = Cache::lock('ai-rag-projection-retention:'.$organizationId, 120);
        if (! $lease->get()) {
            return;
        }
        try {
            $this->deleteOldGenerations($organizationId, $generation, 100000, microtime(true) + 5);
        } finally {
            $lease->release();
        }
    }

    public function discard(int $organizationId, string $generation, int $maxRows = PHP_INT_MAX, ?float $deadline = null): int
    {
        if ($generation === $this->activeGeneration($organizationId)) {
            return 0;
        }
        $key = $this->discardKey($organizationId);
        $pending = (array) Cache::get($key, []);
        $pending[$generation] = true;
        Cache::put($key, $pending, now()->addDays(2));
        $query = RagExpectedSource::query()->where('organization_id', $organizationId)
            ->where('generation', '>=', $generation)->where('generation', '<=', $generation);
        $cursorColumns = ['generation', 'identity_project_id', 'source_type', 'entity_type', 'entity_id', 'identity_part_key'];
        foreach ($cursorColumns as $column) {
            $query->orderBy($column);
        }
        $deleted = $this->deleteBatches($organizationId, $query, $maxRows, $deadline ?? microtime(true) + 40, $cursorColumns);
        if ((clone $query)->reorder('generation')->toBase()->selectRaw('1 AS remaining')->first() === null) {
            $pending = (array) Cache::get($key, []);
            unset($pending[$generation]);
            if ($pending === []) {
                Cache::forget($key);
            } else {
                Cache::put($key, $pending, now()->addDays(2));
            }
        }

        return $deleted;
    }

    /** @return array{deleted: int, locked: bool} */
    public function pruneOrganization(int $organizationId, int $maxRows = 500000, ?float $deadline = null): array
    {
        $lease = Cache::lock('ai-rag-projection-retention:'.$organizationId, 120);
        if (! $lease->get()) {
            return ['deleted' => 0, 'locked' => true];
        }
        try {
            return ['deleted' => $this->pruneWhileLocked($organizationId, $maxRows, $deadline), 'locked' => false];
        } finally {
            $lease->release();
        }
    }

    public function pruneWhileLocked(int $organizationId, int $maxRows = 500000, ?float $deadline = null): int
    {
        $deadline ??= microtime(true) + 50;
        $maxRows = max(0, min(self::MAX_PRUNE_ROWS, $maxRows));
        $deleted = 0;
        $active = $this->activeGeneration($organizationId);
        foreach (array_keys((array) Cache::get($this->discardKey($organizationId), [])) as $generation) {
            if ($deleted >= $maxRows || microtime(true) >= $deadline) {
                break;
            }
            if ($generation !== $active) {
                $deleted += $this->discard($organizationId, (string) $generation, $maxRows - $deleted, $deadline);
            }
        }
        if ($deleted < $maxRows && microtime(true) < $deadline) {
            $deleted += $this->deleteOldGenerations($organizationId, $active, $maxRows - $deleted, $deadline);
        }

        return $deleted;
    }

    private function deleteOldGenerations(int $organizationId, ?string $active, int $maxRows, float $deadline): int
    {
        $query = RagExpectedSource::query()->where('organization_id', $organizationId)
            ->where('created_at', '<', now()->subHours(self::ORPHAN_GRACE_HOURS))
            ->when($active !== null, static fn (Builder $query): Builder => $query->where('generation', '<>', $active))
            ->orderBy('created_at')->orderBy('id');

        return $this->deleteBatches($organizationId, $query, $maxRows, $deadline, ['created_at', 'id']);
    }

    private function deleteBatches(int $organizationId, Builder $query, int $maxRows, float $deadline, array $cursorColumns): int
    {
        $deleted = 0;
        $cursor = null;
        while ($deleted < $maxRows && microtime(true) < $deadline) {
            $batch = clone $query;
            if ($cursor !== null) {
                $batch->whereRowValues($cursorColumns, '>', $cursor);
            }
            $rows = $batch->limit(min(self::DELETE_BATCH_SIZE, $maxRows - $deleted))->get(array_values(array_unique(['id', ...$cursorColumns])));
            if ($rows->isEmpty()) {
                break;
            }
            $last = $rows->last();
            $cursor = array_map(static fn (string $column): mixed => $last->getRawOriginal($column), $cursorColumns);
            $affected = RagExpectedSource::query()->where('organization_id', $organizationId)->whereIn('id', $rows->pluck('id'))->delete();
            $deleted += $affected;
            if ($affected === 0) {
                break;
            }
        }

        return $deleted;
    }

    private function activeGeneration(int $organizationId): ?string
    {
        $generation = app(RagCoverageStateStore::class)->activeGeneration($organizationId);
        if ($generation !== null) {
            return $generation;
        }
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organizationId, 0);
        $snapshot = Cache::get('ai-rag-coverage:'.$organizationId.':0:*:'.$revision);

        return is_array($snapshot) && is_string($snapshot['projection_generation'] ?? null) ? $snapshot['projection_generation'] : null;
    }

    private function discardKey(int $organizationId): string
    {
        return 'ai-rag-coverage-discard-generations:'.$organizationId;
    }
}
