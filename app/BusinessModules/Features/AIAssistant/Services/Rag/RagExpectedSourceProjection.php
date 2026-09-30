<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RagExpectedSourceProjection
{
    public function __construct(private readonly RagIndexer $indexer) {}

    public function stage(int $organizationId, string $sourceType, string $generation, array $batch): void
    {
        $timestamp = now();
        $rows = [];
        foreach ($batch as $chunk) {
            if ($chunk->organizationId !== $organizationId || $chunk->sourceType !== $sourceType) {
                throw new RuntimeException('rag_coverage_collector_scope_mismatch');
            }
            $identity = $this->indexer->coverageIdentity($chunk);
            $changedAt = $chunk->updatedAt === null ? $timestamp : Carbon::instance($chunk->updatedAt);
            $key = json_encode([$identity['identity_project_id'], $identity['identity_part_key'], $identity['entity_type'], $identity['entity_id']], JSON_THROW_ON_ERROR);
            $rows[$key] = $identity + ['generation' => $generation, 'pending_since' => $changedAt->min($timestamp),
                'created_at' => $timestamp, 'updated_at' => $timestamp];
        }
        RagExpectedSource::query()->upsert(array_values($rows),
            ['organization_id', 'generation', 'identity_project_id', 'source_type', 'entity_type', 'entity_id', 'identity_part_key'],
            ['project_id', 'checksum', 'pending_since', 'updated_at']);
    }

    public function actorCounts(int $organizationId, User $actor, AssistantDataAccessPolicy $policy, array $types, string $generation, ?callable $checkpoint = null, ?array &$proof = null): array
    {
        $proof = [];
        if ($checkpoint !== null) { $checkpoint(); }
        $expected = RagExpectedSource::query()->where('ai_rag_expected_sources.organization_id', $organizationId)
            ->where('ai_rag_expected_sources.generation', $generation)->whereIn('ai_rag_expected_sources.source_type', $types);
        $projects = $policy->entityQuery($actor, $organizationId, 'project');
        $expected->where(static function (Builder $scope) use ($projects): void {
            $scope->where('ai_rag_expected_sources.source_type', 'file_document')->orWhereNull('ai_rag_expected_sources.project_id');
            if ($projects !== null) {
                $scope->orWhereIn('ai_rag_expected_sources.project_id', $projects->select('projects.id'));
            }
        });
        $matched = 'matched_sources.id IS NOT NULL AND EXISTS (SELECT 1 FROM ai_rag_chunks matched_chunks WHERE matched_chunks.source_id = matched_sources.id AND matched_chunks.organization_id = expected.organization_id AND matched_chunks.project_id IS NOT DISTINCT FROM expected.project_id AND matched_chunks.embedding IS NOT NULL)';
        $proofColumns = array_map(static fn (string $column): string => "COALESCE(CAST(expected.".$column." AS TEXT), '')",
            ['source_type', 'entity_type', 'entity_id', 'project_id', 'identity_project_id', 'identity_part_key', 'checksum']);
        $counts = [];
        foreach ($policy->sourceIdentityQueries($expected, $actor, $organizationId, $checkpoint, true) as $branch) {
            $visible = $branch->select('ai_rag_expected_sources.*')->toBase();
            if ($checkpoint !== null) { $checkpoint(); }
            $rows = DB::query()->fromSub($visible, 'expected')->leftJoin('ai_rag_sources as matched_sources', static function (JoinClause $join): void {
            foreach (['organization_id', 'identity_project_id', 'identity_part_key', 'source_type', 'entity_type', 'entity_id', 'checksum'] as $column) {
                $join->on('matched_sources.'.$column, '=', 'expected.'.$column);
            }
            $join->whereRaw('matched_sources.project_id IS NOT DISTINCT FROM expected.project_id');
        })->groupBy('expected.source_type')
            ->selectRaw('expected.source_type, COUNT(*) AS expected_count, SUM(CASE WHEN '.$matched.' THEN 1 ELSE 0 END) AS indexed_count, MIN(CASE WHEN NOT ('.$matched.') THEN expected.pending_since END) AS pending_since')
            ->selectRaw('jsonb_object_agg(CAST(expected.id AS TEXT), jsonb_build_array('.implode(', ', $proofColumns).')) AS identity_proof')->get();
            foreach ($rows as $row) {
                $proof += json_decode((string) $row->identity_proof, true, 512, JSON_THROW_ON_ERROR);
                unset($row->identity_proof);
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
        RagExpectedSource::query()->where('organization_id', $organizationId)->where('generation', '<>', $generation)
            ->where('created_at', '<', now()->subMinutes(10))->delete();
    }
}
