<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RagCoverageStateStore
{
    public function revision(int $organizationId): int
    {
        return (int) DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)->value('revision');
    }

    public function invalidate(int $organizationId): int
    {
        return DB::transaction(function () use ($organizationId): int {
            DB::table('ai_rag_coverage_states')->insertOrIgnore([
                'organization_id' => $organizationId, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)
                ->increment('revision', 1, ['acknowledged_index_version' => DB::raw('index_version'), 'updated_at' => now()]);

            return $this->revision($organizationId);
        });
    }

    public function snapshot(int $organizationId, int $revision): ?array
    {
        $json = DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)
            ->where('revision', $revision)->where('published_revision', $revision)->value('snapshot');
        if (! is_string($json)) {
            return null;
        }
        $snapshot = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($snapshot) ? $snapshot : null;
    }

    public function invalidateMany(array $organizationIds, callable $checkpoint): void
    {
        $organizationIds = array_values(array_unique(array_map('intval', $organizationIds)));
        sort($organizationIds, SORT_NUMERIC);
        if ($organizationIds === []) { return; }
        DB::transaction(function () use ($organizationIds, $checkpoint): void {
            $checkpoint();
            DB::table('ai_rag_coverage_states')->insertOrIgnore(array_map(static fn (int $id): array => [
                'organization_id' => $id, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
            ], $organizationIds));
            $checkpoint();
            DB::table('ai_rag_coverage_states')->whereIn('organization_id', $organizationIds)
                ->increment('revision', 1, ['acknowledged_index_version' => DB::raw('index_version'), 'updated_at' => now()]);
        });
    }

    public function markIndexChanged(int $organizationId): void
    {
        DB::table('ai_rag_coverage_states')->insertOrIgnore([
            'organization_id' => $organizationId, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)
            ->increment('index_version', 1, ['updated_at' => now()]);
    }

    public function indexVersion(int $organizationId): int
    {
        return (int) DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)->value('index_version');
    }

    public function hasPendingIndexChanges(int $organizationId): bool
    {
        return DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)
            ->whereColumn('index_version', '>', 'acknowledged_index_version')->exists();
    }

    public function publish(int $organizationId, int $revision, array $snapshot, ?int $indexVersion = null): void
    {
        DB::transaction(function () use ($organizationId, $revision, $snapshot, $indexVersion): void {
            DB::table('ai_rag_coverage_states')->insertOrIgnore([
                'organization_id' => $organizationId, 'revision' => 0, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $state = DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)->lockForUpdate()->first();
            if ($state === null || (int) $state->revision !== $revision) {
                throw new RuntimeException('rag_coverage_projection_expired');
            }
            DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)->update([
                'published_revision' => $revision,
                'acknowledged_index_version' => max((int) $state->acknowledged_index_version, $indexVersion ?? (int) $state->index_version),
                'active_generation' => $snapshot['projection_generation'],
                'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        });
    }

    public function activeGeneration(int $organizationId): ?string
    {
        $generation = DB::table('ai_rag_coverage_states')->where('organization_id', $organizationId)->value('active_generation');

        return is_string($generation) ? $generation : null;
    }
}
