<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use App\BusinessModules\Features\AIAssistant\Jobs\RefreshAssistantIndexStatusJob;
use App\BusinessModules\Features\AIAssistant\Models\RagIndexRun;
use App\BusinessModules\Features\AIAssistant\Models\RagExpectedSource;
use App\BusinessModules\Features\AIAssistant\Models\RagSource;
use App\BusinessModules\Features\AIAssistant\Services\Documents\AssistantDocumentCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagCoverageService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudget;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagStatusBudgetExceeded;
use App\BusinessModules\Features\KnowledgeHub\Enums\KnowledgeSurface;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class AssistantIndexStatusService
{
    private const SNAPSHOT_TTL_SECONDS = 60;
    private const REFRESH_LOCK_SECONDS = 180;
    private const MAX_PROOF_IDENTITIES = 10000;
    private const PROOF_BATCH_SIZE = 250;

    public function __construct(
        private readonly RagCoverageService $coverage,
        private readonly AssistantDocumentCoverageService $documents,
        private readonly RagIndexingCoordinator $coordinator,
        private readonly AssistantDataAccessPolicy $access,
        private readonly AuthorizationService $authorization,
    ) {}

    public function status(int $organizationId, User $actor): array
    {
        if (! $this->access->belongsToOrganization($actor, $organizationId)) {
            throw new AuthorizationException;
        }

        $surface = $this->currentSurface();
        $key = $this->snapshotKey($organizationId, (int) $actor->id, $surface);
        $snapshot = Cache::get($key);
        if (! is_array($snapshot) || ! $this->isFreshSnapshot($snapshot)) {
            $this->queueRefresh($organizationId, (int) $actor->id, $surface, $key);

            return $this->unavailableStatus();
        }

        try {
            $budget = new RagStatusBudget(DB::connection());
            $result = $this->inRepeatableRead(fn (): array => $budget->run(fn (callable $checkpoint): array => $this->access->withCurrentChecks(
                $actor,
                $organizationId,
                function (AuthorizationService $authorization) use ($organizationId, $actor, $snapshot, $checkpoint): array {
                    $valid = $this->validateRagProof($organizationId, $actor, $snapshot['proof']['rag'] ?? [], $checkpoint)
                        && $this->documents->validateStatusProof($organizationId, $actor, $snapshot['proof']['documents'] ?? [], $checkpoint);
                    if (! $valid) {
                        return ['valid' => false];
                    }

                    return [
                        'valid' => true,
                        'can_reindex' => $authorization->canCurrent($actor, 'admin.ai_assistant.rag.manage', ['organization_id' => $organizationId]),
                        'can_manage_document_settings' => $this->documents->canManageSettings($organizationId, $actor),
                    ];
                },
                fresh: true,
                checkpoint: $budget->checkDeadline(...),
            )));
        } catch (RagStatusBudgetExceeded|QueryException $exception) {
            if ($exception instanceof QueryException && ($exception->errorInfo[0] ?? null) !== '57014') {
                throw $exception;
            }
            $result = ['valid' => false];
        }

        if (! ($result['valid'] ?? false)) {
            $this->queueRefresh($organizationId, (int) $actor->id, $surface, $key);

            return $this->unavailableStatus();
        }

        $status = $snapshot['status'];
        $status['can_reindex'] = (bool) ($result['can_reindex'] ?? false);
        $status['can_manage_document_settings'] = (bool) ($result['can_manage_document_settings'] ?? false);
        $status['stale_after_seconds'] = self::SNAPSHOT_TTL_SECONDS;

        return $status;
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
        foreach (array_chunk(array_keys($proof), self::PROOF_BATCH_SIZE) as $ids) {
            $checkpoint();
            $query = (clone $base)->whereIn(($expected ? 'ai_rag_expected_sources' : 'ai_rag_sources').'.id', $ids);
            $columns = $expected
                ? ['ai_rag_expected_sources.id', 'ai_rag_expected_sources.source_type', 'ai_rag_expected_sources.entity_type',
                    'ai_rag_expected_sources.entity_id', 'ai_rag_expected_sources.project_id', 'ai_rag_expected_sources.identity_project_id',
                    'ai_rag_expected_sources.identity_part_key', 'ai_rag_expected_sources.checksum']
                : ['ai_rag_sources.id', 'ai_rag_sources.source_type', 'ai_rag_sources.entity_type', 'ai_rag_sources.entity_id',
                    'ai_rag_sources.project_id', 'ai_rag_sources.identity_part_key', 'ai_rag_sources.source_version', 'ai_rag_sources.checksum'];
            $scoped = $expected
                ? $this->access->applyToExpectedSources($query, $actor, $organizationId)
                : $this->access->applyToSources($query, $actor, $organizationId);
            $visible = [];
            foreach ($scoped->select($columns)->get() as $row) {
                $visible[(string) $row->id] = $expected ? $this->expectedProofRow($row) : $this->sourceProofRow($row);
            }
            $expectedRows = array_intersect_key($proof, array_flip($ids));
            ksort($visible);
            ksort($expectedRows);
            if ($visible !== $expectedRows) {
                return false;
            }
        }

        return true;
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

    private function queueRefresh(int $organizationId, int $actorId, KnowledgeSurface $surface, string $key): void
    {
        $queuedKey = $key.':queued';
        if (! Cache::add($queuedKey, true, self::REFRESH_LOCK_SECONDS)) {
            return;
        }
        try {
            dispatch(new RefreshAssistantIndexStatusJob($organizationId, $actorId, $surface, $key));
        } catch (Throwable $exception) {
            Cache::forget($queuedKey);
            Log::warning('ai_assistant.rag.status_refresh_queue_failed', [
                'organization_id' => $organizationId,
                'user_id' => $actorId,
                'exception_class' => $exception::class,
            ]);
        }
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
