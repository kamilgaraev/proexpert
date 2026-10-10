<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudget;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\BusinessModules\Features\AIAssistant\Jobs\RefreshAssistantIndexStatusJob;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotEpoch;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotInputs;
use App\BusinessModules\Features\AIAssistant\Services\StatusSnapshots\AssistantStatusSnapshotDiagnostics;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use App\Services\Monitoring\ApiQueryMetrics;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class AssistantIndexStatusService
{
    private const SNAPSHOT_TTL_SECONDS = 60;
    private const MAX_PROOF_IDENTITIES = 10000;
    private const PROOF_BATCH_SIZE = 250;

    public function __construct(
        private readonly RagCoverageService $coverage,
        private readonly AssistantDocumentCoverageService $documents,
        private readonly RagIndexingCoordinator $coordinator,
        private readonly AssistantDataAccessPolicy $access,
        private readonly AuthorizationService $authorization,
        private readonly ?AssistantStatusSnapshotEpoch $snapshotEpoch = null,
        private readonly ?AssistantStatusSnapshotInputs $snapshotInputs = null,
    ) {}

    public function status(int $organizationId, User $actor, string $section = 'all'): array
    {
        if (! in_array($section, ['all', 'sources', 'documents'], true)) {
            throw new \InvalidArgumentException('Unsupported assistant status section');
        }
        if (! $this->access->belongsToOrganization($actor, $organizationId)) {
            throw new AuthorizationException;
        }

        $checkCurrentAccess = $this->checksAssistantAccess();
        if ($checkCurrentAccess && $section !== 'sources') {
            return $this->snapshotStatus($organizationId, $actor, $section);
        }

        $phase = (object) ['value' => 'schema', 'missing_release' => false];
        $started = hrtime(true);
        $metricsRequest = request();
        $processingPhase = 'rag_prepare';
        $processingCheckpoint = ApiQueryMetrics::processingCheckpoint($metricsRequest);
        $setProcessingPhase = static function (?string $next) use ($metricsRequest, &$processingPhase, &$processingCheckpoint): void {
            if ($processingPhase !== null && $processingCheckpoint !== null) {
                ApiQueryMetrics::recordProcessingPhase($metricsRequest, $processingPhase, $processingCheckpoint['started_at'], $processingCheckpoint);
            }
            $processingPhase = $next;
            $processingCheckpoint = $next === null ? null : ApiQueryMetrics::processingCheckpoint($metricsRequest);
        };
        try {
            $budget = new RagStatusBudget(DB::connection(), 2500);
            $result = $this->inRepeatableRead(fn (): array => $budget->run(function (callable $checkpoint) use ($organizationId, $actor, $budget, $section, $phase, $checkCurrentAccess, $setProcessingPhase): array {
                if ($checkCurrentAccess) {
                    $actor = User::query()->find($actor->id);
                    if ($actor === null || ! $actor->is_active || (int) $actor->current_organization_id !== $organizationId) {
                        throw new AuthorizationException;
                    }
                }

                return $this->access->withCurrentChecks(
                    $actor,
                    $organizationId,
                    function (AuthorizationService $authorization) use ($organizationId, $actor, $checkpoint, $budget, $section, $phase, $checkCurrentAccess, $setProcessingPhase): array {
                        $validUntil = null;
                        if ($checkCurrentAccess) {
                            if (! $this->access->canReadDomain($actor, $organizationId, 'assistant')) { throw new AuthorizationException; }
                            $inputs = $this->snapshotInputs ?? app(AssistantStatusSnapshotInputs::class);
                            if ($inputs->fingerprint($actor, $organizationId, $this->currentSurface(), $section, request()->ip(), $authorization) === null) {
                                $phase->missing_release = true;
                                AssistantStatusSnapshotDiagnostics::request('missing_release', $section);

                                return $this->unavailableStatus();
                            }
                            $capturedAt = DB::selectOne('SELECT LEAST(transaction_timestamp(), clock_timestamp())::text AS captured_at')->captured_at;
                            $validUntil = $inputs->validUntil($capturedAt, self::SNAPSHOT_TTL_SECONDS, $organizationId, (int) $actor->id);
                        }
                        $setProcessingPhase('rag_schema_prefetch');
                        $this->access->prefetchEntitySchemaMetadata($checkpoint, $budget->checkDeadline(...));
                        $setProcessingPhase('rag_source_prepare');
                        $projectionProof = null;
                        $coverage = $section === 'documents' ? [] : $this->coverage->coverageForActor($organizationId, $actor, $checkpoint, $budget->checkDeadline(...), $projectionProof, countsOnly: true,
                            progress: static function (string $value) use ($phase, $setProcessingPhase): void {
                                $phase->value = $value;
                                if (in_array($value, ['source_acl', 'source_counts', 'expected_counts'], true)) {
                                    $setProcessingPhase('rag_'.$value);
                                }
                            });
                        $phase->value = 'documents';
                        $setProcessingPhase($section === 'sources' ? 'rag_finalize' : 'rag_documents');
                        $documents = $section === 'sources' ? [] : $this->documents->coverage($organizationId, $actor, $checkpoint, $budget->checkDeadline(...));
                        if ($section !== 'sources') { $setProcessingPhase('rag_finalize'); }

                        $canReindex = $authorization->canCurrent($actor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]);
                        $budget->checkDeadline();
                        if ($checkCurrentAccess && ! $inputs->isUnexpired($validUntil)) {
                            AssistantStatusSnapshotDiagnostics::request('fresh_read_unavailable', $section);

                            return $this->unavailableStatus();
                        }

                        return array_merge($coverage, $documents, [
                            'status_available' => true,
                            'can_reindex' => $canReindex,
                            'stale_after_seconds' => self::SNAPSHOT_TTL_SECONDS,
                        ]);
                    },
                    fresh: true,
                    checkpoint: $budget->checkDeadline(...),
                );
            }));
        } catch (RagStatusBudgetExceeded|QueryException $exception) {
            if ($exception instanceof QueryException && ($exception->errorInfo[0] ?? null) !== '57014') {
                throw $exception;
            }
            \Illuminate\Support\Facades\Log::warning('assistant.rag_status_budget_exceeded', [
                'organization_id' => $organizationId, 'actor_id' => (int) $actor->id, 'section' => $section,
                'phase' => $phase->value, 'elapsed_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);
            $result = $this->unavailableStatus();
            if ($checkCurrentAccess && ! $phase->missing_release) { AssistantStatusSnapshotDiagnostics::request('fresh_read_unavailable', $section); }
        } finally {
            $setProcessingPhase(null);
        }

        if ($checkCurrentAccess && ($result['status_available'] ?? false)) { AssistantStatusSnapshotDiagnostics::request('fresh_read', $section); }

        return $result;
    }

    public function checksAssistantAccess(): bool
    {
        return config('ai-assistant.status_snapshots', app()->environment('production')) && DB::connection()->getDriverName() === 'pgsql';
    }

    private function snapshotStatus(int $organizationId, User $actor, string $section): array
    {
        $surface = $this->currentSurface();
        $ip = request()->ip();
        $epoch = $this->snapshotEpoch ?? app(AssistantStatusSnapshotEpoch::class);
        $inputs = $this->snapshotInputs ?? app(AssistantStatusSnapshotInputs::class);

        return $this->inRepeatableRead(function () use ($organizationId, $actor, $section, $surface, $ip, $epoch, $inputs): array {
            $currentActor = User::query()->find($actor->id);
            if ($currentActor === null || ! $currentActor->is_active || (int) $currentActor->current_organization_id !== $organizationId) {
                throw new AuthorizationException;
            }

            return $this->access->withCurrentChecks($currentActor, $organizationId, function (AuthorizationService $authorization) use ($organizationId, $currentActor, $section, $surface, $ip, $epoch, $inputs): array {
                if (! $this->access->canReadDomain($currentActor, $organizationId, 'assistant')) { throw new AuthorizationException; }
                $fingerprint = $inputs->fingerprint($currentActor, $organizationId, $surface, $section, $ip, $authorization);
                if ($fingerprint === null) {
                    AssistantStatusSnapshotDiagnostics::request('missing_release', $section);

                    return $this->unavailableStatus();
                }
                $key = $this->snapshotKey($organizationId, (int) $currentActor->id, $surface).':'.$section.':'.$fingerprint;
                $snapshot = Cache::get($key);
                $age = $inputs->age(is_array($snapshot) ? ($snapshot['generated_at'] ?? null) : null);
                $canReindex = $authorization->canCurrent($currentActor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]);
                if (is_array($snapshot) && is_array($snapshot['status'] ?? null) && is_array($snapshot['epoch'] ?? null)
                    && is_array($snapshot['decisions'] ?? null) && ($snapshot['fingerprint'] ?? null) === $fingerprint
                    && $inputs->isUnexpired($snapshot['valid_until'] ?? null)
                    && $age !== null
                    && ($snapshot['projection_generation'] ?? null) === $this->currentProjectionGeneration($organizationId)
                    && $epoch->isValid($snapshot['epoch'], self::SNAPSHOT_TTL_SECONDS, $organizationId)
                    && $inputs->decisionsMatch($snapshot['decisions'], $currentActor, $authorization)
                    && $this->access->withCurrentChecks($currentActor, $organizationId,
                        static fn (AuthorizationService $current): bool => $current === $authorization)) {
                    AssistantStatusSnapshotDiagnostics::request('ready', $section, $key, $age);
                    $status = $snapshot['status'];
                    $status['can_reindex'] = $canReindex;
                    if (is_numeric($status['lag_seconds'] ?? null)
                        && ((int) ($status['pending_source_count'] ?? 0) > 0 || $status['lag_seconds'] > 0)) {
                        $status['lag_seconds'] += $age;
                        $status['lag_exceeded'] = $status['lag_seconds'] > (int) ($status['lag_goal_seconds'] ?? 300);
                    }

                    return $status;
                }
                $queued = Cache::add($key.':queued', true, 90);
                AssistantStatusSnapshotDiagnostics::request(is_array($snapshot) ? 'snapshot_rejected' : 'snapshot_missing', $section, $key, $age, $queued);
                if ($queued) {
                    try {
                        RefreshAssistantIndexStatusJob::dispatch($organizationId, (int) $currentActor->id, $surface, $key, $section, $ip)->afterCommit();
                    } catch (Throwable $exception) {
                        Cache::forget($key.':queued');
                        throw $exception;
                    }
                }

                return $this->unavailableStatus();
            }, fresh: true);
        });
    }

    public function refreshSnapshot(int $organizationId, int $actorId, KnowledgeSurface $surface, string $cacheKey): void
    {
        if ($this->access->trustedSurface() !== $surface) {
            throw new \LogicException('Assistant status refresh surface mismatch');
        }

        $actor = User::query()->find($actorId);
        if ($actor === null || ! $this->access->belongsToOrganization($actor, $organizationId)) {
            return;
        }

        $budget = new RagStatusBudget(DB::connection(), 60000);
        $snapshot = $this->inRepeatableRead(fn (): array => $budget->run(fn (callable $checkpoint): array => $this->access->withCurrentChecks(
            $actor,
            $organizationId,
            function (AuthorizationService $authorization) use ($organizationId, $actor, $checkpoint, $budget): array {
                $projectionProof = null;
                $coverage = $this->coverage->coverageForActor($organizationId, $actor, $checkpoint, $budget->checkDeadline(...), $projectionProof);
                $documents = $this->documents->coverage($organizationId, $actor, $checkpoint, $budget->checkDeadline(...));
                $ragProof = $this->captureRagProof($organizationId, $actor, $checkpoint, $projectionProof);
                if (($coverage['eligible_count_known'] ?? false) && ! is_array($ragProof['expected'] ?? null)) {
                    $coverage = $this->withoutUnprovenExpectedCoverage($coverage);
                }
                $nativeTypes = array_keys($documents['native_attachment_coverage'] ?? []);
                $documentProof = $this->documents->captureStatusProof(
                    $organizationId,
                    $actor,
                    $nativeTypes,
                    self::MAX_PROOF_IDENTITIES,
                    $budget->checkDeadline(...),
                );
                $identityCount = $this->proofIdentityCount($ragProof) + $this->proofIdentityCount($documentProof);

                return [
                    'status' => array_merge($coverage, $documents, [
                        'status_available' => true,
                        'enabled' => (bool) config('ai-assistant.rag.enabled', true),
                        'can_reindex' => $authorization->canCurrent($actor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]),
                        'stale_after_seconds' => self::SNAPSHOT_TTL_SECONDS,
                    ]),
                    'proof' => ['rag' => $ragProof, 'documents' => $documentProof],
                    'proof_complete' => ($documentProof['_complete'] ?? false)
                        && $identityCount <= self::MAX_PROOF_IDENTITIES
                        && (! ($coverage['eligible_count_known'] ?? false) || is_array($ragProof['expected'] ?? null)),
                    'generated_at' => now()->toIso8601String(),
                ];
            },
            fresh: true,
            checkpoint: $budget->checkDeadline(...),
        )));

        if (($snapshot['proof_complete'] ?? false) && is_string($snapshot['generated_at'] ?? null)) {
            Cache::put($cacheKey, $snapshot, self::SNAPSHOT_TTL_SECONDS);
        }
    }

    public function reindex(int $organizationId, User $actor, array $input): RagIndexRun
    {
        if (! $this->canReindex($organizationId, $actor)) {
            throw new AuthorizationException;
        }
        $projectId = isset($input['project_id']) ? (int) $input['project_id'] : null;
        if ($projectId !== null && ! $this->access->canReadEntity($actor, $organizationId, 'project', $projectId)) {
            throw new AuthorizationException;
        }

        return $this->coordinator->queueOrganization(
            $organizationId,
            $projectId,
            isset($input['source_type']) ? trim((string) $input['source_type']) : null,
            RagIndexRun::MODE_MANUAL,
        );
    }

    /** @return array<string, mixed> */
    private function captureRagProof(int $organizationId, User $actor, callable $checkpoint, ?array $projectionProof): array
    {
        $checkpoint();
        $sources = [];
        $sourceQuery = $this->access->applyToSources(RagSource::query(), $actor, $organizationId);
        foreach ($sourceQuery->select(['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.entity_type',
            'ai_rag_sources.entity_id', 'ai_rag_sources.project_id', 'ai_rag_sources.identity_part_key',
            'ai_rag_sources.source_version', 'ai_rag_sources.checksum'])->get() as $source) {
            $sources[(string) $source->id] = $this->sourceProofRow($source);
        }

        $generation = is_string($projectionProof['projection_generation'] ?? null)
            ? $projectionProof['projection_generation']
            : null;
        $expected = is_array($projectionProof['expected'] ?? null) ? $projectionProof['expected'] : null;

        return ['generation' => $generation, 'sources' => $sources, 'expected' => $expected];
    }

    private function validateRagProof(int $organizationId, User $actor, array $proof, callable $checkpoint): bool
    {
        $sources = $proof['sources'] ?? null;
        $expected = $proof['expected'] ?? null;
        if (! is_array($sources) || (! is_array($expected) && $expected !== null)) {
            return false;
        }

        $currentGeneration = $this->currentProjectionGeneration($organizationId);
        if ($expected !== null && ($proof['generation'] ?? null) !== $currentGeneration) {
            return false;
        }

        if (! $this->validateIdentityProof(RagSource::query(), $organizationId, $actor, $sources, $checkpoint, false)) {
            return false;
        }

        return $expected === null || $this->validateIdentityProof(
            RagExpectedSource::query()->where('generation', $currentGeneration),
            $organizationId,
            $actor,
            $expected,
            $checkpoint,
            true,
        );
    }

    private function validateIdentityProof(\Illuminate\Database\Eloquent\Builder $base, int $organizationId, User $actor, array $proof, callable $checkpoint, bool $expected): bool
    {
        if ($proof === []) {
            return true;
        }
        if (count($proof) > self::MAX_PROOF_IDENTITIES) {
            return false;
        }

        $identities = [];
        foreach ($proof as $row) {
            if (! is_array($row) || ! is_string($row[0] ?? null) || ! is_string($row[1] ?? null)) {
                return false;
            }
            $identities[$row[0]."\0".$row[1]] = [$row[0], $row[1]];
        }

        $table = $expected ? 'ai_rag_expected_sources' : 'ai_rag_sources';
        $identityScoped = clone $base;
        $identityScoped->where(function (\Illuminate\Database\Eloquent\Builder $identityScope) use ($identities, $table): void {
            $identityScope->whereRaw('1 = 0');
            foreach ($identities as [$sourceType, $entityType]) {
                $identityScope->orWhere(function (\Illuminate\Database\Eloquent\Builder $identity) use ($table, $sourceType, $entityType): void {
                    $identity->where($table.'.source_type', $sourceType)->where($table.'.entity_type', $entityType);
                });
            }
        });
        $checkpoint();
        $scoped = $expected
            ? $this->access->applyToExpectedSources($identityScoped, $actor, $organizationId)
            : $this->access->applyToSources($identityScoped, $actor, $organizationId);

        $columns = $expected
            ? ['ai_rag_expected_sources.id', 'ai_rag_expected_sources.source_type', 'ai_rag_expected_sources.entity_type',
                'ai_rag_expected_sources.entity_id', 'ai_rag_expected_sources.project_id', 'ai_rag_expected_sources.identity_project_id',
                'ai_rag_expected_sources.identity_part_key', 'ai_rag_expected_sources.checksum']
            : ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.entity_type', 'ai_rag_sources.entity_id',
                'ai_rag_sources.project_id', 'ai_rag_sources.identity_part_key', 'ai_rag_sources.source_version', 'ai_rag_sources.checksum'];
        $query = (clone $scoped)->whereIn($table.'.id', array_keys($proof))->toBase()->select($columns);
        $checkpoint();
        $validated = 0;
        foreach ($query->cursor() as $row) {
            if ($validated > 0 && $validated % self::PROOF_BATCH_SIZE === 0) {
                $checkpoint();
            }
            $id = (string) $row->id;
            $expectedRow = $proof[$id] ?? null;
            if (! is_array($expectedRow)
                || ($expected ? $this->expectedProofRow($row) : $this->sourceProofRow($row)) !== $expectedRow) {
                return false;
            }
            $validated++;
        }

        $checkpoint();

        return $validated === count($proof);
    }

    private function currentProjectionGeneration(int $organizationId): ?string
    {
        $revision = (int) Cache::get('ai-rag-coverage-revision:'.$organizationId, 0);
        $projection = Cache::get('ai-rag-coverage:'.$organizationId.':0:*:'.$revision);

        return is_array($projection) && is_string($projection['projection_generation'] ?? null)
            ? $projection['projection_generation']
            : null;
    }

    private function sourceProofRow(object $source): array
    {
        return [(string) $source->source_type, (string) $source->entity_type, (string) $source->entity_id,
            (string) $source->project_id, (string) $source->identity_part_key, (string) $source->source_version, (string) $source->checksum];
    }

    private function expectedProofRow(object $source): array
    {
        return [(string) $source->source_type, (string) $source->entity_type, (string) $source->entity_id,
            (string) $source->project_id, (string) $source->identity_project_id, (string) $source->identity_part_key, (string) $source->checksum];
    }

    private function proofIdentityCount(array $proof): int
    {
        return count($proof['sources'] ?? []) + count($proof['expected'] ?? [])
            + count($proof['files'] ?? []) + count($proof['documents'] ?? [])
            + array_sum(array_map('count', $proof['entities'] ?? []))
            + array_sum(array_map('count', $proof['content_entities'] ?? []));
    }

    private function withoutUnprovenExpectedCoverage(array $coverage): array
    {
        $coverage['expected_source_count'] = null;
        $coverage['indexed_source_count'] = array_sum(array_map(
            static fn (array $source): int => (int) ($source['indexed_count'] ?? 0),
            $coverage['source_catalog'] ?? [],
        ));
        $coverage['pending_source_count'] = null;
        $coverage['stale_source_count'] = null;
        $coverage['eligible_count_known'] = false;
        $coverage['coverage_complete'] = false;
        $coverage['lag_seconds'] = null;
        $coverage['lag_exceeded'] = false;
        $coverage['snapshot_at'] = null;
        $catalog = $coverage['source_catalog'] ?? [];
        foreach ($catalog as &$source) {
            $source['expected_count'] = null;
            $source['pending_count'] = null;
            $source['stale_count'] = null;
        }
        unset($source);
        $coverage['source_catalog'] = $catalog;

        return $coverage;
    }

    private function isFreshSnapshot(array $snapshot): bool
    {
        if (! ($snapshot['proof_complete'] ?? false) || ! is_array($snapshot['status'] ?? null)
            || ! is_array($snapshot['proof']['rag'] ?? null) || ! is_array($snapshot['proof']['documents'] ?? null)
            || ! is_string($snapshot['generated_at'] ?? null)) {
            return false;
        }
        try {
            $timestamp = Carbon::parse($snapshot['generated_at']);

            return ! $timestamp->isFuture() && $timestamp->gte(now()->subSeconds(self::SNAPSHOT_TTL_SECONDS));
        } catch (Throwable) {
            return false;
        }
    }

    private function snapshotKey(int $organizationId, int $actorId, KnowledgeSurface $surface): string
    {
        return 'ai-rag-status:'.$organizationId.':'.$actorId.':'.$surface->value;
    }

    private function currentSurface(): KnowledgeSurface
    {
        $trusted = $this->access->trustedSurface();
        if ($trusted !== null) {
            if ($trusted === KnowledgeSurface::SUPERADMIN) {
                throw new \InvalidArgumentException('Unsupported assistant surface');
            }

            return $trusted;
        }

        $request = request();

        return $request->is('api/v1/admin/*') ? KnowledgeSurface::ADMIN
            : ($request->is('api/v1/mobile/*') ? KnowledgeSurface::MOBILE : KnowledgeSurface::LK);
    }

    private function inRepeatableRead(callable $operation): mixed
    {
        $connection = DB::connection();
        if ($connection->transactionLevel() > 0 && app()->environment('testing')) {
            return $operation();
        }

        return $connection->transaction(function () use ($connection, $operation): mixed {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $operation();
        }, 1);
    }

    private function unavailableStatus(): array
    {
        return [
            'status_available' => false,
            'enabled' => (bool) config('ai-assistant.rag.enabled', true),
            'ready' => false,
            'coverage_complete' => false,
            'eligible_count_known' => false,
            'source_count' => null,
            'chunk_count' => null,
            'stale_after_seconds' => self::SNAPSHOT_TTL_SECONDS,
        ];
    }

    private function canReindex(int $organizationId, User $actor): bool
    {
        return $this->authorization->canCurrent($actor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]);
    }
}
