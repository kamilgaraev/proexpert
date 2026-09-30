<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagSearchResult;
use App\BusinessModules\Features\AIAssistant\Services\AssistantDataAccessPolicy;
use App\BusinessModules\Features\AIAssistant\Services\UsageTracker;
use App\BusinessModules\Features\AIAssistant\Services\AssistantReadConcurrencyLimiter;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\Models\User;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use App\Services\Project\UserProjectAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class RagRetriever
{
    public function __construct(
        private readonly RagEmbeddingProviderInterface $embeddingProvider,
        private readonly UserProjectAccessService $projectAccessService,
        private readonly ?AssistantDataAccessPolicy $accessPolicy = null,
        private readonly ?UsageTracker $usageTracker = null,
        private readonly ?AssistantReadConcurrencyLimiter $readLimiter = null
    ) {}

    /**
     * @param  array<string, mixed>  $requestContext
     * @return array<int, RagSearchResult>
     */
    public function search(string $query, int $organizationId, User $user, array $requestContext = [], ?callable $checkpoint = null, ?callable $readWrapper = null, ?callable $readGuard = null): array
    {
        return $this->searchWithDiagnostics($query, $organizationId, $user, $requestContext, $checkpoint, $readWrapper, $readGuard)['results'];
    }

    public function searchWithDiagnostics(string $query, int $organizationId, User $user, array $requestContext = [], ?callable $checkpoint = null, ?callable $readWrapper = null, ?callable $readGuard = null): array
    {
        $diagnostics = ['status' => 'available', 'error_code' => null, 'semantic_available' => true, 'lexical_used' => false];
        $results = $this->executeSearch($query, $organizationId, $user, $requestContext, $checkpoint, $readWrapper, $readGuard, $diagnostics);
        return ['results' => $results, 'diagnostics' => $diagnostics];
    }

    private function executeSearch(string $query, int $organizationId, User $user, array $requestContext, ?callable $checkpoint, ?callable $readWrapper, ?callable $readGuard, array &$diagnostics): array
    {
        $checkpoint?->__invoke();
        if (! $this->belongsToOrganization($user, $organizationId)) {
            return [];
        }

        $limit = $this->configInt('ai-assistant.rag.max_chunks', 8);
        if (isset($requestContext['limit']) && is_numeric($requestContext['limit'])) { $limit = max(1, min($limit, (int) $requestContext['limit'])); }
        $threshold = $this->configFloat('ai-assistant.rag.min_similarity', 0.72);
        $requestProjectId = $this->requestProjectId($requestContext);
        $allowedProjectIds = $this->allowedProjectIds($user, $organizationId);
        if ($requestProjectId !== null && ! in_array($requestProjectId, $allowedProjectIds, true)) { return []; }
        $preferredSourceTypes = $this->preferredSourceTypes($query);
        $typeCandidates = RagSource::query()->where('ai_rag_sources.organization_id', $organizationId)
            ->whereExists(static function ($chunks) use ($organizationId, $requestProjectId, $allowedProjectIds): void {
                $chunks->selectRaw('1')->from('ai_rag_chunks as candidate_chunks')->whereColumn('candidate_chunks.source_id', 'ai_rag_sources.id')
                    ->where('candidate_chunks.organization_id', $organizationId)->where(static function ($projects) use ($requestProjectId, $allowedProjectIds): void {
                        $projects->whereNull('candidate_chunks.project_id');
                        if ($requestProjectId !== null) { $projects->orWhere('candidate_chunks.project_id', $requestProjectId); }
                        elseif ($allowedProjectIds !== []) { $projects->orWhereIn('candidate_chunks.project_id', $allowedProjectIds); }
                    });
            });
        if ($preferredSourceTypes !== []) { $typeCandidates->whereIn('ai_rag_sources.source_type', $preferredSourceTypes); }
        if (isset($requestContext['source_types']) && is_array($requestContext['source_types'])) {
            $typeCandidates->whereIn('ai_rag_sources.source_type', array_filter($requestContext['source_types'], 'is_string'));
        }
        $checkpoint?->__invoke();
        $candidateTypes = $typeCandidates->distinct()->pluck('source_type')->all();
        $allowedSourceTypes = $this->accessPolicy()->allowedSourceTypes($user, $organizationId, $candidateTypes);
        if (isset($requestContext['source_types']) && is_array($requestContext['source_types'])) {
            $allowedSourceTypes = array_values(array_intersect($allowedSourceTypes, array_filter($requestContext['source_types'], 'is_string')));
        }
        $sourceTypes = $preferredSourceTypes === []
            ? $allowedSourceTypes
            : array_values(array_intersect($preferredSourceTypes, $allowedSourceTypes));
        $includeOrganizationWideSources = $preferredSourceTypes === [] || $this->includeOrganizationWideSources($sourceTypes);

        if ($sourceTypes === []) {
            return [];
        }

        if ($requestProjectId !== null && ! in_array($requestProjectId, $allowedProjectIds, true)) {
            return [];
        }

        $checkpoint?->__invoke();
        try {
            [$embedding, $cacheHit] = $this->timed('query_embedding', $organizationId, $user, fn (): array => $this->queryEmbedding($query, $organizationId, $user));
        } catch (Throwable $throwable) {
            $this->rethrowExecutionFailure($throwable);
            $checkpoint?->__invoke();
            $this->recordQueryEmbeddingUsage($query, $organizationId, $user, $requestProjectId, false);
            Log::warning('ai_assistant.rag.query_embedding_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $throwable::class,
            ]);

            $diagnostics = ['status' => 'unavailable', 'error_code' => 'rag_query_embedding_unavailable', 'semantic_available' => false, 'lexical_used' => true];
            $results = $this->lexicalFallback(
                $query,
                $organizationId,
                $allowedProjectIds,
                $requestProjectId,
                $limit,
                $sourceTypes,
                $includeOrganizationWideSources,
                [],
                $user,
                $checkpoint,
                $readWrapper,
                $readGuard
            );
            if ($results !== []) { $diagnostics['status'] = 'partial'; }
            return $results;
        }

        if (! $cacheHit) { $this->recordQueryEmbeddingUsage($query, $organizationId, $user, $requestProjectId); }
        $checkpoint?->__invoke();
        $accessibleSources = $this->timed('source_acl', $organizationId, $user, fn (): array => $this->runRead(fn (?callable $readCheckpoint): array => $this->authorizedSourceIds($organizationId, $user, $sourceTypes, $allowedProjectIds, $requestProjectId, $includeOrganizationWideSources, $embedding, [], $readCheckpoint), $checkpoint, $organizationId, $readWrapper, $readGuard));
        $accessibleSources = RagSource::query()->whereIntegerInRaw('id', $accessibleSources)->select('id');

        $results = [];
        $checkpoint?->__invoke();
        $rows = $this->timed('semantic_sql', $organizationId, $user, fn (): iterable => $this->runRead(fn (): iterable => $this->candidateRows(
            $embedding,
            $organizationId,
            max($limit * 4, $limit),
            $sourceTypes,
            $allowedProjectIds,
            $requestProjectId,
            $includeOrganizationWideSources,
            $accessibleSources
        ), $checkpoint, $organizationId, $readWrapper, $readGuard));
        $checkpoint?->__invoke();
        $rows = $this->readableRows($rows, $user, $organizationId, $checkpoint, $readWrapper, $readGuard);
        foreach ($rows as $row) {
            $checkpoint?->__invoke();
            $projectId = $row->project_id !== null ? (int) $row->project_id : null;

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
            $diagnostics['lexical_used'] = true;
            return $this->lexicalFallback(
                $query,
                $organizationId,
                $allowedProjectIds,
                $requestProjectId,
                $limit,
                $sourceTypes,
                $includeOrganizationWideSources,
                [],
                $user,
                $checkpoint,
                $readWrapper,
                $readGuard
            );
        }

        return $results;
    }

    private function queryEmbedding(string $query, int $organizationId, User $actor): array
    {
        $key = 'ai_assistant:query_embedding:'.hash('sha256', json_encode([
            $organizationId, (int) $actor->id, RagEmbeddingProviderInterface::PURPOSE_QUERY,
            $this->embeddingProvider->provider(), $this->embeddingProvider->model(), $this->embeddingProvider->dimensions(), $query,
        ], JSON_THROW_ON_ERROR));
        $cached = null;
        try { $cached = Cache::get($key); }
        catch (Throwable $exception) {
            $this->rethrowExecutionFailure($exception);
            Log::warning('ai_assistant.rag.query_embedding_cache_failed', ['organization_id' => $organizationId, 'user_id' => $actor->id, 'operation' => 'read', 'exception_class' => $exception::class]);
        }
        if ($this->validEmbedding($cached)) { return [$cached, true]; }
        $embedding = $this->embeddingProvider->embed($query, RagEmbeddingProviderInterface::PURPOSE_QUERY);
        if (! $this->validEmbedding($embedding)) { throw new \RuntimeException('rag_query_embedding_invalid'); }
        try { Cache::put($key, $embedding, 3600); }
        catch (Throwable $exception) {
            $this->rethrowExecutionFailure($exception);
            Log::warning('ai_assistant.rag.query_embedding_cache_failed', ['organization_id' => $organizationId, 'user_id' => $actor->id, 'operation' => 'write', 'exception_class' => $exception::class]);
        }
        return [$embedding, false];
    }

    private function rethrowExecutionFailure(Throwable $exception): void
    {
        if ($exception instanceof AssistantRequestCancelled || $exception instanceof AssistantRequestDeadlineExceeded
            || $exception instanceof RagStatusBudgetExceeded || $exception instanceof QueryException) { throw $exception; }
    }

    private function validEmbedding(mixed $embedding): bool
    {
        if (! is_array($embedding) || ! array_is_list($embedding) || count($embedding) !== $this->embeddingProvider->dimensions()) { return false; }
        foreach ($embedding as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) { return false; }
        }
        return true;
    }

    private function timed(string $phase, int $organizationId, User $actor, callable $operation): mixed
    {
        $started = hrtime(true);
        try { return $operation(); }
        finally {
            Log::info('ai_assistant.rag.phase', ['organization_id' => $organizationId, 'user_id' => $actor->id,
                'phase' => $phase, 'duration_ms' => round((hrtime(true) - $started) / 1_000_000, 2)]);
        }
    }

    private function runRead(callable $operation, ?callable $checkpoint, int $organizationId, ?callable $readWrapper = null, ?callable $readGuard = null): mixed
    {
        $read = function (?callable $permitCheckpoint = null) use ($operation, $checkpoint, $readGuard, $readWrapper): mixed {
            if ($readGuard !== null) { $checkpoint?->__invoke(); }
            $currentReadGuard = $permitCheckpoint ?? $readGuard ?? $checkpoint;
            $currentReadGuard?->__invoke();
            $result = $readWrapper === null ? $operation($currentReadGuard) : $readWrapper(fn () => $operation($currentReadGuard));
            $currentReadGuard?->__invoke();
            if ($readGuard !== null) { $checkpoint?->__invoke(); }
            return $result;
        };
        return $checkpoint === null || $this->readLimiter === null
            ? $read()
            : $this->readLimiter->run($read, $checkpoint, $organizationId, $readGuard);
    }

    private function readableRows(iterable $rows, User $actor, int $organizationId, ?callable $checkpoint, ?callable $readWrapper, ?callable $readGuard): array
    {
        return $this->timed('result_acl', $organizationId, $actor, fn (): array => $this->runRead(function (?callable $readCheckpoint) use ($rows, $actor, $organizationId): array {
            return $this->accessPolicy()->withCurrentChecks($actor, $organizationId, function () use ($rows, $actor, $organizationId, $readCheckpoint): array {
                $visible = [];
                foreach ($rows as $row) {
                    $readCheckpoint?->__invoke();
                    if ($this->accessPolicy()->canReadSource($actor, $organizationId, [
                        'source_type' => $row->source_type, 'entity_type' => $row->entity_type, 'entity_id' => $row->entity_id,
                        'project_id' => $row->project_id, 'metadata' => $this->metadata($row->chunk_metadata),
                    ])) { $visible[] = $row; }
                }
                return $visible;
            }, fresh: true, checkpoint: $readCheckpoint);
        }, $checkpoint, $organizationId, $readWrapper, $readGuard));
    }

    private function authorizedSourceIds(int $organizationId, User $actor, array $sourceTypes, array $projectIds, ?int $projectId, bool $includeOrganizationWideSources, ?array $embedding, array $terms, ?callable $checkpoint): array
    {
        $policy = $this->accessPolicy();
        return $policy->withCurrentChecks($actor, $organizationId, function () use ($policy, $organizationId, $actor, $sourceTypes, $projectIds, $projectId, $includeOrganizationWideSources, $embedding, $terms, $checkpoint): array {
            $candidates = RagSource::query()->where('ai_rag_sources.organization_id', $organizationId)->whereIn('ai_rag_sources.source_type', $sourceTypes)
                ->whereExists(function ($chunks) use ($organizationId, $projectIds, $projectId, $includeOrganizationWideSources, $embedding, $terms): void {
                    $chunks->selectRaw('1')->from('ai_rag_chunks as candidate_chunks')
                        ->whereColumn('candidate_chunks.source_id', 'ai_rag_sources.id')->where('candidate_chunks.organization_id', $organizationId);
                    if ($projectId !== null) {
                        $chunks->where(function ($projects) use ($projectId, $includeOrganizationWideSources): void {
                            $projects->where('candidate_chunks.project_id', $projectId);
                            if ($includeOrganizationWideSources) { $projects->orWhereNull('candidate_chunks.project_id'); }
                        });
                    } else {
                        $chunks->where(function ($projects) use ($projectIds, $includeOrganizationWideSources, $embedding): void {
                            $projects->whereIn('candidate_chunks.project_id', $projectIds);
                            if ($includeOrganizationWideSources || $embedding === null) { $projects->orWhereNull('candidate_chunks.project_id'); }
                        });
                    }
                    if ($embedding !== null) {
                        $chunks->whereNotNull('candidate_chunks.embedding')->where('candidate_chunks.embedding_provider', $this->embeddingProvider->provider())
                            ->where('candidate_chunks.embedding_model', $this->embeddingProvider->model());
                        if (DB::connection()->getDriverName() === 'pgsql') { $chunks->whereRaw('vector_dims(candidate_chunks.embedding) = ?', [count($embedding)]); }
                    } else {
                        $chunks->where(function ($matches) use ($terms): void {
                            foreach ($terms as $term) {
                                $pattern = '%'.$term.'%';
                                $titlePattern = '%'.mb_convert_case($term, MB_CASE_TITLE, 'UTF-8').'%';
                                $matches->orWhereRaw('lower(candidate_chunks.content) like ?', [$pattern])->orWhereRaw('lower(ai_rag_sources.title) like ?', [$pattern])
                                    ->orWhere('candidate_chunks.content', 'like', $titlePattern)->orWhere('ai_rag_sources.title', 'like', $titlePattern);
                            }
                        });
                    }
                });
            $ids = [];
            $branches = 0;
            $maxSqlBytes = 0;
            foreach ($policy->sourceIdentityQueries($candidates, $actor, $organizationId, $checkpoint) as $accessible) {
                $checkpoint?->__invoke();
                $accessible->select('ai_rag_sources.id');
                $maxSqlBytes = max($maxSqlBytes, strlen($accessible->toSql()));
                array_push($ids, ...$accessible->pluck('id')->all());
                $branches++;
                $checkpoint?->__invoke();
            }
            Log::info('ai_assistant.rag.source_scope', ['organization_id' => $organizationId, 'user_id' => $actor->id,
                'mode' => $embedding === null ? 'lexical' : 'semantic', 'branches' => $branches, 'source_count' => count($ids), 'max_sql_bytes' => $maxSqlBytes]);
            return array_values(array_unique($ids));
        }, fresh: true, checkpoint: $checkpoint);
    }

    private function recordQueryEmbeddingUsage(
        string $query,
        int $organizationId,
        User $user,
        ?int $requestProjectId,
        bool $successful = true
    ): void {
        try {
            $usage = $this->embeddingUsage($query);
            $tracker = $this->usageTracker ?? app(UsageTracker::class);
            $attempts = method_exists($this->embeddingProvider, 'usageAttempts') ? $this->embeddingProvider->usageAttempts() : [];
            if (! is_array($attempts) || $attempts === []) $attempts = [$usage + ['is_successful' => $successful, 'usage_key' => 'embedding:'.bin2hex(random_bytes(16))]];
            foreach ($attempts as $attempt) {
                $usage = OpenAIRagEmbeddingProvider::usageEvidence($attempt, $query);
                $tracker->recordUsage(
                    $organizationId,
                    $user->id,
                    $this->embeddingProvider->provider(),
                    $this->embeddingProvider->model(),
                    'rag_query',
                    $usage['input_tokens'],
                    $usage['output_tokens'],
                    $usage['total_tokens'],
                    [
                        'purpose' => RagEmbeddingProviderInterface::PURPOSE_QUERY,
                        'project_id' => $requestProjectId,
                        'text_chars' => mb_strlen($query, 'UTF-8'),
                        'usage_source' => $usage['usage_source'],
                        'provider_usage_available' => $usage['provider_usage_available'],
                        'estimated_input_tokens' => $usage['estimated_input_tokens'],
                        'pricing_estimate' => false,
                        'usage_key' => $attempt['usage_key'] ?? null,
                        'attempt' => $attempt['attempt'] ?? 1,
                        'is_successful' => $attempt['is_successful'] ?? $successful,
                    ]
                );
            }
        } catch (Throwable $throwable) {
            $this->rethrowExecutionFailure($throwable);
            Log::warning('ai_assistant.rag.query_usage_record_failed', [
                'organization_id' => $organizationId,
                'user_id' => $user->id,
                'exception_class' => $throwable::class,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function embeddingUsage(string $content): array
    {
        $provider = $this->embeddingProvider;

        if (method_exists($provider, 'lastUsage')) {
            try {
                $usage = $provider->lastUsage();

                if (is_array($usage)) {
                    return OpenAIRagEmbeddingProvider::usageEvidence($usage, $content);
                }
            } catch (Throwable $exception) {
                $this->rethrowExecutionFailure($exception);
            }
        }

        return OpenAIRagEmbeddingProvider::usageEvidence(null, $content);
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
        bool $includeOrganizationWideSources,
        Builder|array $accessibleSources,
        User $actor,
        ?callable $checkpoint = null,
        ?callable $readWrapper = null,
        ?callable $readGuard = null
    ): array {
        $terms = $this->lexicalTerms($query);
        if ($terms === []) {
            return [];
        }

        $checkpoint?->__invoke();
        $accessibleSources = $this->timed('lexical_source_acl', $organizationId, $actor, fn (): array => $this->runRead(fn (?callable $readCheckpoint): array => $this->authorizedSourceIds($organizationId, $actor, $sourceTypes, $allowedProjectIds, $requestProjectId, $includeOrganizationWideSources, null, $terms, $readCheckpoint), $checkpoint, $organizationId, $readWrapper, $readGuard));
        $accessibleSources = RagSource::query()->whereIntegerInRaw('id', $accessibleSources)->select('id');
        $checkpoint?->__invoke();
        $order = implode(' + ', array_fill(0, count($terms), "CASE WHEN lower(c.content || ' ' || s.title) LIKE ? THEN 1 ELSE 0 END"));
        $orderBindings = array_map(static fn (string $term): string => '%'.$term.'%', $terms);
        $rows = $this->timed('lexical_sql', $organizationId, $actor, fn () => $this->runRead(fn () => DB::table('ai_rag_chunks as c')
            ->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
            ->where('c.organization_id', $organizationId)
            ->where('s.organization_id', $organizationId)
            ->whereIn('s.id', $accessibleSources)
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
            ->orderByRaw('('.$order.') DESC', $orderBindings)
            ->limit(max($limit * 12, 48))
            ->get(), $checkpoint, $organizationId, $readWrapper, $readGuard));
        $checkpoint?->__invoke();

        return collect($this->readableRows($rows, $actor, $organizationId, $checkpoint, $readWrapper, $readGuard))
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
    private function candidateRows(
        array $embedding,
        int $organizationId,
        int $limit,
        array $sourceTypes,
        array $allowedProjectIds,
        ?int $requestProjectId,
        bool $includeOrganizationWideSources,
        Builder|array $accessibleSources
    ): iterable
    {
        return DB::connection()->getDriverName() === 'pgsql'
            ? $this->postgresRows($embedding, $organizationId, $limit, $sourceTypes, $allowedProjectIds, $requestProjectId, $includeOrganizationWideSources, $accessibleSources)
            : $this->fallbackRows($embedding, $organizationId, $limit, $sourceTypes, $allowedProjectIds, $requestProjectId, $includeOrganizationWideSources, $accessibleSources);
    }

    /**
     * @param  array<int, float>  $embedding
     * @param  array<int, string>  $sourceTypes
     * @return array<int, object>
     */
    private function postgresRows(array $embedding, int $organizationId, int $limit, array $sourceTypes, array $allowedProjectIds, ?int $requestProjectId, bool $includeOrganizationWideSources, Builder|array $accessibleSources): array
    {
        $vector = $this->vectorLiteral($embedding);
        $sourceFilter = '';
        $bindings = [$organizationId, $this->embeddingProvider->provider(), $this->embeddingProvider->model(), count($embedding)];

        if ($sourceTypes !== []) {
            $sourceFilter = '  AND s.source_type IN ('.implode(',', array_fill(0, count($sourceTypes), '?')).')'."\n";
            array_push($bindings, ...$sourceTypes);
        }

        $sourceSql = $accessibleSources instanceof Builder ? $accessibleSources->toSql() : ($accessibleSources === [] ? 'NULL' : implode(',', array_fill(0, count($accessibleSources), '?')));
        $sourceFilter .= '  AND s.organization_id = ? AND s.id IN ('.$sourceSql.')'."\n";
        $bindings[] = $organizationId;
        array_push($bindings, ...($accessibleSources instanceof Builder ? $accessibleSources->getBindings() : $accessibleSources));
        $projectFilter = $this->postgresProjectFilter($bindings, $allowedProjectIds, $requestProjectId, $includeOrganizationWideSources, $accessibleSources);

        $bindings[] = $vector;
        $bindings[] = $vector;
        $bindings[] = $limit;

        $sql = <<<SQL
WITH compatible_chunks AS MATERIALIZED (
SELECT c.id,
       c.project_id,
       c.content,
       c.metadata AS chunk_metadata,
       s.source_type,
       s.entity_type,
       s.entity_id,
       s.title,
       s.indexed_at AS source_indexed_at,
       c.embedding
FROM ai_rag_chunks c
JOIN ai_rag_sources s ON s.id = c.source_id
WHERE c.organization_id = ?
  AND c.embedding IS NOT NULL
  AND c.embedding_provider = ?
  AND c.embedding_model = ?
  AND vector_dims(c.embedding) = ?
{$sourceFilter}{$projectFilter})
SELECT id, project_id, content, chunk_metadata, source_type, entity_type, entity_id, title, source_indexed_at,
       1 - (embedding <=> ?::vector) AS similarity
FROM compatible_chunks
ORDER BY embedding <=> ?::vector
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
    private function fallbackRows(array $embedding, int $organizationId, int $limit, array $sourceTypes, array $allowedProjectIds, ?int $requestProjectId, bool $includeOrganizationWideSources, Builder|array $accessibleSources): Collection
    {
        return DB::table('ai_rag_chunks as c')
            ->join('ai_rag_sources as s', 's.id', '=', 'c.source_id')
            ->where('c.organization_id', $organizationId)
            ->where('s.organization_id', $organizationId)
            ->whereIn('s.id', $accessibleSources)
            ->whereNotNull('c.embedding')
            ->where('c.embedding_provider', $this->embeddingProvider->provider())
            ->where('c.embedding_model', $this->embeddingProvider->model())
            ->when(
                $sourceTypes !== [],
                static fn ($builder) => $builder->whereIn('s.source_type', $sourceTypes)
            )
            ->where(function ($query) use ($allowedProjectIds, $requestProjectId, $includeOrganizationWideSources): void {
                if ($requestProjectId !== null) {
                    $query->where('c.project_id', $requestProjectId);
                    if ($includeOrganizationWideSources) {
                        $query->orWhereNull('c.project_id');
                    }

                    return;
                }

                $query->whereIn('c.project_id', $allowedProjectIds);
                if ($includeOrganizationWideSources) {
                    $query->orWhereNull('c.project_id');
                }
            })
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
            ->filter(fn (object $row): bool => count($this->parseVector((string) $row->embedding)) === count($embedding))
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

        if ($this->hasAnyMarker($normalized, ['справочник', 'справочн', 'норматив', 'расценк', 'каталог'])) {
            return $this->hasAnyMarker($normalized, ['смет', 'сметн'])
                ? ['estimate', 'estimate_reference']
                : ['estimate_reference'];
        }

        if ($this->isFinanceQuery($normalized)) {
            return ['payment', 'contract', 'performance_act', 'project', 'estimate', 'project_pulse'];
        }

        return [];
    }

    /**
     * @param  array<int, string>  $sourceTypes
     */
    private function includeOrganizationWideSources(array $sourceTypes): bool
    {
        return array_intersect($sourceTypes, $this->accessPolicy()->organizationWideSourceTypes()) !== [];
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

        return array_values(array_unique(array_merge($terms, $this->expandedLexicalTerms($terms, $normalized))));
    }

    /**
     * @param  array<int, string>  $terms
     * @return array<int, string>
     */
    private function expandedLexicalTerms(array $terms, string $normalizedQuery): array
    {
        if (! $this->isFinanceQuery($normalizedQuery) && ! in_array('финанс', $terms, true)) {
            return [];
        }

        return [
            'платеж',
            'оплат',
            'сумма',
            'оплачено',
            'остаток',
            'счет',
            'счёт',
            'договор',
            'контракт',
            'бюджет',
            'акт',
            'прибыл',
            'рентабельн',
        ];
    }

    private function isFinanceQuery(string $normalizedQuery): bool
    {
        foreach (['финанс', 'бюджет', 'затрат', 'расход', 'оплат', 'платеж', 'платёж', 'счет', 'счёт', 'прибыл', 'рентабельн', 'маржинальн'] as $marker) {
            if (str_contains($normalizedQuery, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, string>  $markers
     */
    private function hasAnyMarker(string $normalizedQuery, array $markers): bool
    {
        foreach ($markers as $marker) {
            if (str_contains($normalizedQuery, $marker)) {
                return true;
            }
        }

        return false;
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

    private function accessPolicy(): AssistantDataAccessPolicy
    {
        return $this->accessPolicy ?? app(AssistantDataAccessPolicy::class);
    }

    /** @param array<int, mixed> $bindings @param array<int, int> $allowedProjectIds */
    private function postgresProjectFilter(array &$bindings, array $allowedProjectIds, ?int $requestProjectId, bool $includeOrganizationWideSources, Builder|array $accessibleSources): string
    {
        if ($requestProjectId !== null) {
            $bindings[] = $requestProjectId;

            return $includeOrganizationWideSources
                ? "  AND (c.project_id = ? OR c.project_id IS NULL)\n"
                : "  AND c.project_id = ?\n";
        }

        if ($allowedProjectIds === []) {
            return $includeOrganizationWideSources ? "  AND c.project_id IS NULL\n" : "  AND 1 = 0\n";
        }

        array_push($bindings, ...$allowedProjectIds);
        $filter = 'c.project_id IN ('.implode(',', array_fill(0, count($allowedProjectIds), '?')).')';

        return $includeOrganizationWideSources ? "  AND (".$filter." OR c.project_id IS NULL)\n" : '  AND '.$filter."\n";
    }

    private function belongsToOrganization(User $user, int $organizationId): bool
    {
        return $this->accessPolicy()->belongsToOrganization($user, $organizationId);
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
        if (count($left) !== count($right)) return 0.0;
        $count = count($left);
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
