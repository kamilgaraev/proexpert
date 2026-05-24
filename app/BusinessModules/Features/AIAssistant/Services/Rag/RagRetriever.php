<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\Models\User;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RagRetriever
{
    public function __construct(
        private readonly RagEmbeddingProviderInterface $embeddingProvider,
        private readonly UserProjectAccessService $projectAccessService
    ) {
    }

    /**
     * @param  array<string, mixed>  $requestContext
     * @return array<int, RagSearchResult>
     */
    public function search(string $query, int $organizationId, User $user, array $requestContext = []): array
    {
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return [];
        }

        $limit = $this->configInt('ai-assistant.rag.max_chunks', 8);
        $threshold = $this->configFloat('ai-assistant.rag.min_similarity', 0.72);
        $requestProjectId = $this->requestProjectId($requestContext);
        $allowedProjectIds = $this->allowedProjectIds($user, $organizationId);

        if ($requestProjectId !== null && ! in_array($requestProjectId, $allowedProjectIds, true)) {
            return [];
        }

        try {
            $embedding = $this->embeddingProvider->embed($query, RagEmbeddingProviderInterface::PURPOSE_QUERY);
        } catch (Throwable $throwable) {
            Log::warning('ai_assistant.rag.query_embedding_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $throwable::class,
            ]);

            return [];
        }

        $results = [];
        foreach ($this->candidateRows($embedding, $organizationId, max($limit * 4, $limit)) as $row) {
            $projectId = $row->project_id !== null ? (int) $row->project_id : null;

            if ($projectId !== null && ! in_array($projectId, $allowedProjectIds, true)) {
                continue;
            }

            if ($requestProjectId !== null && $projectId !== $requestProjectId) {
                continue;
            }

            $similarity = (float) $row->similarity;
            if ($similarity < $threshold) {
                continue;
            }

            $results[] = new RagSearchResult(
                sourceType: (string) $row->source_type,
                entityType: (string) $row->entity_type,
                entityId: (string) $row->entity_id,
                projectId: $projectId,
                title: (string) $row->title,
                excerpt: $this->excerpt((string) $row->content),
                similarity: $similarity,
                metadata: $this->metadata($row->chunk_metadata),
                updatedAt: $this->date($row->source_indexed_at)
            );

            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, float>  $embedding
     * @return iterable<object>
     */
    private function candidateRows(array $embedding, int $organizationId, int $limit): iterable
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $this->postgresRows($embedding, $organizationId, $limit)
            : $this->fallbackRows($embedding, $organizationId, $limit);
    }

    /**
     * @param  array<int, float>  $embedding
     * @return array<int, object>
     */
    private function postgresRows(array $embedding, int $organizationId, int $limit): array
    {
        $vector = $this->vectorLiteral($embedding);

        return DB::select(
            <<<'SQL'
SELECT c.id,
       c.project_id,
       c.content,
       c.metadata AS chunk_metadata,
       s.source_type,
       s.entity_type,
       s.entity_id,
       s.title,
       s.indexed_at AS source_indexed_at,
       1 - (c.embedding <=> ?::vector) AS similarity
FROM ai_rag_chunks c
JOIN ai_rag_sources s ON s.id = c.source_id
WHERE c.organization_id = ?
  AND c.embedding IS NOT NULL
ORDER BY c.embedding <=> ?::vector
LIMIT ?
SQL,
            [$vector, $organizationId, $vector, $limit]
        );
    }

    /**
     * @param  array<int, float>  $embedding
     * @return Collection<int, object>
     */
    private function fallbackRows(array $embedding, int $organizationId, int $limit): Collection
    {
        return DB::table('ai_rag_chunks as c')
            ->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
            ->where('c.organization_id', $organizationId)
            ->whereNotNull('c.embedding')
            ->select([
                'c.id',
                'c.project_id',
                'c.content',
                'c.metadata as chunk_metadata',
                'c.embedding',
                's.source_type',
                's.entity_type',
                's.entity_id',
                's.title',
                's.indexed_at as source_indexed_at',
            ])
            ->get()
            ->map(function (object $row) use ($embedding): object {
                $row->similarity = $this->cosineSimilarity($embedding, $this->parseVector((string) $row->embedding));

                return $row;
            })
            ->sortByDesc(static fn (object $row): float => (float) $row->similarity)
            ->take($limit)
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function allowedProjectIds(User $user, int $organizationId): array
    {
        return $this->projectAccessService
            ->queryAccessibleProjects($user, $organizationId)
            ->pluck('projects.id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    private function belongsToOrganization(User $user, int $organizationId): bool
    {
        try {
            if (method_exists($user, 'isSystemAdmin') && $user->isSystemAdmin()) {
                return true;
            }

            if (method_exists($user, 'belongsToOrganization')) {
                return $user->belongsToOrganization($organizationId);
            }
        } catch (Throwable) {
            return false;
        }

        return (int) $user->current_organization_id === $organizationId;
    }

    /**
     * @param  array<string, mixed>  $requestContext
     */
    private function requestProjectId(array $requestContext): ?int
    {
        $projectId = $requestContext['project_id'] ?? null;

        return is_numeric($projectId) ? (int) $projectId : null;
    }

    private function excerpt(string $content): string
    {
        $content = preg_replace('/\s+/u', ' ', trim($content)) ?? trim($content);
        $content = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $content) ?? $content;

        if (mb_strlen($content) <= 340) {
            return $content;
        }

        return rtrim(mb_substr($content, 0, 337)).'...';
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }

        if (! is_string($metadata) || trim($metadata) === '') {
            return [];
        }

        $decoded = json_decode($metadata, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, float>  $embedding
     */
    private function vectorLiteral(array $embedding): string
    {
        return '['.implode(',', array_map(
            static fn (float $value): string => rtrim(rtrim(sprintf('%.12F', $value), '0'), '.') ?: '0',
            $embedding
        )).']';
    }

    /**
     * @return array<int, float>
     */
    private function parseVector(string $vector): array
    {
        $vector = trim($vector, "[] \t\n\r\0\x0B");

        if ($vector === '') {
            return [];
        }

        return array_map(static fn (string $value): float => (float) $value, explode(',', $vector));
    }

    /**
     * @param  array<int, float>  $left
     * @param  array<int, float>  $right
     */
    private function cosineSimilarity(array $left, array $right): float
    {
        $count = min(count($left), count($right));
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        for ($index = 0; $index < $count; $index++) {
            $dot += $left[$index] * $right[$index];
            $leftNorm += $left[$index] ** 2;
            $rightNorm += $right[$index] ** 2;
        }

        if ($leftNorm <= 0.0 || $rightNorm <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    private function configInt(string $key, int $default): int
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    private function configFloat(string $key, float $default): float
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) ? (float) $value : $default;
    }
}
