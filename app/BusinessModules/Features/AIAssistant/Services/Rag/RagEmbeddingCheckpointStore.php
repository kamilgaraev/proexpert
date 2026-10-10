<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use Illuminate\Support\Facades\DB;

final class RagEmbeddingCheckpointStore
{
    public function load(int $organizationId, string $sourceKey, string $profileKey, array $hashes, int $dimensions): array
    {
        $vectors = [];
        $rows = DB::table('ai_rag_embedding_checkpoints')->where('organization_id', $organizationId)
            ->where('source_key', $sourceKey)->where('profile_key', $profileKey)
            ->whereIn('content_hash', array_unique($hashes))->where('expires_at', '>', now())
            ->get(['content_hash', 'embedding', 'created_at']);
        foreach ($rows as $row) {
            $values = json_decode($row->embedding, true);
            if (! is_array($values) || ! array_is_list($values) || count($values) !== $dimensions) {
                continue;
            }
            foreach ($values as $value) {
                if (! is_numeric($value) || ! is_finite((float) $value)) {
                    continue 2;
                }
            }
            $vectors[$row->content_hash] = ['vector' => $row->embedding, 'created_at' => $row->created_at];
        }

        return $vectors;
    }

    public function save(int $organizationId, string $sourceKey, string $profileKey, string $hash, string $vector): void
    {
        DB::table('ai_rag_embedding_checkpoints')->upsert([
            'organization_id' => $organizationId, 'source_key' => $sourceKey, 'profile_key' => $profileKey,
            'content_hash' => $hash, 'embedding' => $vector, 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ], ['organization_id', 'source_key', 'profile_key', 'content_hash'], ['embedding', 'expires_at', 'created_at', 'updated_at']);
    }

    public function discard(int $organizationId, string $sourceKey): void
    {
        DB::table('ai_rag_embedding_checkpoints')->where('organization_id', $organizationId)->where('source_key', $sourceKey)->delete();
    }

    public function prune(?int $organizationId, int $maxRows, float $deadline): int
    {
        $deleted = 0;
        while ($deleted < $maxRows && microtime(true) < $deadline) {
            $query = DB::table('ai_rag_embedding_checkpoints')->where('expires_at', '<=', now())
                ->when($organizationId !== null, static fn ($query) => $query->where('organization_id', $organizationId));
            $ids = (clone $query)->orderBy('expires_at')->orderBy('id')->limit(min(1000, $maxRows - $deleted))->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $affected = $query->whereIn('id', $ids)->delete();
            $deleted += $affected;
            if ($affected === 0) {
                break;
            }
        }

        return $deleted;
    }
}
