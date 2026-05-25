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
        $preferredSourceTypes = $this->preferredSourceTypes($query);
        $includeOrganizationWideSources = $this->includeOrganizationWideSources($preferredSourceTypes);

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

            return $this->lexicalFallback(
                $query,
                $organizationId,
                $allowedProjectIds,
                $requestProjectId,
                $limit,
                $preferredSourceTypes,
                $includeOrganizationWideSources
            );
        }

        $results = [];
        foreach ($this->candidateRows($embedding, $organizationId, max($limit * 4, $limit), $preferredSourceTypes) as $row) {
            $projectId = $row->project_id !== null ? (int) $row->project_id : null;

            if ($projectId !== null && ! in_array($projectId, $allowedProjectIds, true)) {
                continue;
            }

            if (
                $requestProjectId !== null
                && $projectId !== $requestProjectId
                && ! ($includeOrganizationWideSources && $projectId === null)
            ) {
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

        if ($results === []) {
            return $this->lexicalFallback(
                $query,
                $organizationId,
                $allowedProjectIds,
                $requestProjectId,
                $limit,
                $preferredSourceTypes,
                $includeOrganizationWideSources
            );
        }

        return $results;
    }

    /**
     * @param  array<int, int>  $allowedProjectIds
     * @param  array<int, string>  $sourceTypes
     * @return array<int, RagSearchResult>
     */
    private function lexicalFallback(
        string $query,
        int $organizationId,
        array $allowedProjectIds,
        ?int $requestProjectId,
        int $limit,
        array $sourceTypes,
        bool $includeOrganizationWideSources
    ): array {
        $terms = $this->lexicalTerms($query);
        if ($terms === []) {
            return [];
        }

        $rows = DB::table('ai_rag_chunks as c')
            ->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
            ->where('c.organization_id', $organizationId)
            ->whereNotNull('c.embedding')
            ->when(
                $sourceTypes !== [],
                static fn ($builder) => $builder->whereIn('s.source_type', $sourceTypes)
            )
            ->when(
                $requestProjectId !== null,
                static fn ($builder) => $builder->where(static function ($query) use ($requestProjectId, $includeOrganizationWideSources): void {
                    $query->where('c.project_id', $requestProjectId);

                    if ($includeOrganizationWideSources) {
                        $query->orWhereNull('c.project_id');
                    }
                }),
                static fn ($builder) => $builder->where(static function ($query) use ($allowedProjectIds): void {
                    $query->whereNull('c.project_id');

                    if ($allowedProjectIds !== []) {
                        $query->orWhereIn('c.project_id', $allowedProjectIds);
                    }
                })
            )
            ->where(static function ($builder) use ($terms): void {
                foreach ($terms as $term) {
                    $lowerPattern = '%'.$term.'%';
                    $titlePattern = '%'.mb_convert_case($term, MB_CASE_TITLE, 'UTF-8').'%';

                    $builder
                        ->orWhereRaw('lower(c.content) like ?', [$lowerPattern])
                        ->orWhereRaw('lower(s.title) like ?', [$lowerPattern])
                        ->orWhere('c.content', 'like', $titlePattern)
                        ->orWhere('s.title', 'like', $titlePattern);
                }
            })
            ->select([
                'c.id',
                'c.project_id',
                'c.content',
                'c.metadata as chunk_metadata',
                's.source_type',
                's.entity_type',
                's.entity_id',
                's.title',
                's.indexed_at as source_indexed_at',
            ])
            ->limit(max($limit * 12, 48))
            ->get();

        return $rows
            ->map(function (object $row) use ($terms): object {
                $row->lexical_score = $this->lexicalScore($row, $terms);

                return $row;
            })
            ->filter(static fn (object $row): bool => (int) $row->lexical_score > 0)
            ->sortByDesc(static fn (object $row): int => (int) $row->lexical_score)
            ->take($limit)
            ->values()
            ->map(fn (object $row): RagSearchResult => new RagSearchResult(
                sourceType: (string) $row->source_type,
                entityType: (string) $row->entity_type,
                entityId: (string) $row->entity_id,
                projectId: $row->project_id !== null ? (int) $row->project_id : null,
                title: (string) $row->title,
                excerpt: $this->excerpt((string) $row->content),
                similarity: min(0.69, 0.5 + ((int) $row->lexical_score * 0.03)),
                metadata: $this->metadata($row->chunk_metadata),
                updatedAt: $this->date($row->source_indexed_at)
            ))
            ->all();
    }

    /**
     * @param  array<int, float>  $embedding
     * @param  array<int, string>  $sourceTypes
     * @return iterable<object>
     */
    private function candidateRows(array $embedding, int $organizationId, int $limit, array $sourceTypes): iterable
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $this->postgresRows($embedding, $organizationId, $limit, $sourceTypes)
            : $this->fallbackRows($embedding, $organizationId, $limit, $sourceTypes);
    }

    /**
     * @param  array<int, float>  $embedding
     * @param  array<int, string>  $sourceTypes
     * @return array<int, object>
     */
    private function postgresRows(array $embedding, int $organizationId, int $limit, array $sourceTypes): array
    {
        $vector = $this->vectorLiteral($embedding);
        $sourceFilter = '';
        $bindings = [$vector, $organizationId];

        if ($sourceTypes !== []) {
            $sourceFilter = '  AND s.source_type IN ('.implode(',', array_fill(0, count($sourceTypes), '?')).')'."\n";
            array_push($bindings, ...$sourceTypes);
        }

        $bindings[] = $vector;
        $bindings[] = $limit;

        $sql = <<<SQL
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
{$sourceFilter}ORDER BY c.embedding <=> ?::vector
LIMIT ?
SQL;

        return DB::select(
            $sql,
            $bindings
        );
    }

    /**
     * @param  array<int, float>  $embedding
     * @param  array<int, string>  $sourceTypes
     * @return Collection<int, object>
     */
    private function fallbackRows(array $embedding, int $organizationId, int $limit, array $sourceTypes): Collection
    {
        return DB::table('ai_rag_chunks as c')
            ->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
            ->where('c.organization_id', $organizationId)
            ->whereNotNull('c.embedding')
            ->when(
                $sourceTypes !== [],
                static fn ($builder) => $builder->whereIn('s.source_type', $sourceTypes)
            )
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
     * @return array<int, string>
     */
    private function preferredSourceTypes(string $query): array
    {
        $normalized = str_replace('ё', 'е', mb_strtolower($query));

        foreach (['справочник', 'справочн', 'норматив', 'расценк', 'каталог'] as $marker) {
            if (str_contains($normalized, $marker)) {
                return ['estimate_reference'];
            }
        }

        return [];
    }

    /**
     * @param  array<int, string>  $sourceTypes
     */
    private function includeOrganizationWideSources(array $sourceTypes): bool
    {
        return in_array('estimate_reference', $sourceTypes, true);
    }

    /**
     * @return array<int, string>
     */
    private function lexicalTerms(string $query): array
    {
        $normalized = str_replace('ё', 'е', mb_strtolower($query));
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];
        $stopWords = [
            'база',
            'базе',
            'базы',
            'в',
            'дай',
            'данным',
            'для',
            'есть',
            'знаний',
            'знаешь',
            'из',
            'или',
            'какие',
            'какой',
            'контекст',
            'контекста',
            'контексте',
            'краткую',
            'на',
            'по',
            'сводку',
            'текущим',
            'текущих',
            'текущие',
            'ты',
            'укажи',
            'что',
        ];
        $terms = [];

        foreach ($tokens as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4 || in_array($token, $stopWords, true)) {
                continue;
            }

            $term = $this->stemLexicalTerm($token);
            if (mb_strlen($term) < 4 || in_array($term, $stopWords, true)) {
                continue;
            }

            $terms[] = $term;
        }

        return array_values(array_unique($terms));
    }

    private function stemLexicalTerm(string $term): string
    {
        foreach ([
            'иями',
            'ями',
            'ами',
            'ого',
            'ему',
            'ому',
            'ыми',
            'ими',
            'ая',
            'ую',
            'ые',
            'ий',
            'ый',
            'ой',
            'ей',
            'ам',
            'ах',
            'ов',
            'ев',
            'ия',
            'ие',
            'ы',
            'и',
            'а',
            'у',
            'е',
            'я',
            'ю',
        ] as $ending) {
            if (str_ends_with($term, $ending) && mb_strlen($term) - mb_strlen($ending) >= 4) {
                return mb_substr($term, 0, -mb_strlen($ending));
            }
        }

        return $term;
    }

    /**
     * @param  array<int, string>  $terms
     */
    private function lexicalScore(object $row, array $terms): int
    {
        $haystack = str_replace('ё', 'е', mb_strtolower((string) $row->title.' '.(string) $row->content));
        $score = 0;

        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) {
                $score++;
            }
        }

        return $score;
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
