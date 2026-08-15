<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final readonly class EloquentDocumentProcessingUnitStore implements DocumentProcessingUnitStore
{
    private const SYSTEMIC_FAILURE_THRESHOLD = 2;

    public function __construct(
        private Connection $database,
        private ?DocumentUnitPublicationWriter $publicationWriter = null,
    ) {}

    public function create(int $organizationId, int $projectId, int $sessionId, int $documentId, DocumentUnitData $unit): DocumentProcessingUnitRecord
    {
        $model = $this->query()->firstOrCreate(
            ['document_id' => $documentId, 'unit_type' => $unit->type->value, 'unit_index' => $unit->index, 'source_version' => $unit->sourceVersion],
            ['organization_id' => $organizationId, 'project_id' => $projectId, 'session_id' => $sessionId, 'status' => DocumentProcessingUnitStatus::Pending, 'locator' => $unit->locator],
        );

        return $this->record($model);
    }

    public function find(int $unitId): ?DocumentProcessingUnitRecord
    {
        $model = $this->query()->find($unitId);

        return $model instanceof EstimateGenerationProcessingUnit ? $this->record($model) : null;
    }

    public function executionContext(DocumentProcessingUnitClaim $claim): ?DocumentUnitExecutionContext
    {
        if (! $claim->acquired()) {
            return null;
        }

        return $this->database->transaction(function () use ($claim): DocumentUnitExecutionContext {
            $unit = $this->query()->with('document.session')->lockForUpdate()->find($claim->unitId);
            $now = now()->toDateTimeImmutable();

            if (! $unit instanceof EstimateGenerationProcessingUnit
                || ! $unit->document instanceof EstimateGenerationDocument
                || ! $unit->document->session instanceof EstimateGenerationSession
                || ! $this->isCurrent($unit, (string) $claim->sourceVersion)) {
                throw new DocumentUnitProcessingException('unit_claim_lost');
            }
            (new DocumentUnitExecutionOwnershipGuard)->assertOwned(
                status: $unit->status->value,
                storedToken: (string) $unit->claim_token,
                claimToken: (string) $claim->token,
                storedSourceVersion: (string) $unit->source_version,
                claimSourceVersion: (string) $claim->sourceVersion,
                leaseExpiresAt: $unit->lease_expires_at?->toDateTimeImmutable(),
                now: $now,
                storedScope: [(int) $unit->organization_id, (int) $unit->project_id, (int) $unit->session_id, (int) $unit->document_id],
                claimScope: [$claim->organizationId, $claim->projectId, $claim->sessionId, $claim->documentId],
            );

            $pageId = $this->pageIdentity($unit);

            $locator = (array) $unit->locator;
            $metadata = is_array($unit->metadata) ? $unit->metadata : [];
            if (($metadata['analysis_escalation_reason'] ?? null) === 'cross_document_reference') {
                $locator['analysis_escalation_reason'] = 'cross_document_reference';
            }

            return new DocumentUnitExecutionContext(
                (int) $unit->id,
                (int) $unit->organization_id,
                (int) $unit->project_id,
                (int) $unit->session_id,
                (int) $unit->document_id,
                $unit->unit_type,
                (int) $unit->unit_index,
                (string) $unit->source_version,
                $locator,
                (string) $unit->document->storage_path,
                (string) ($unit->document->mime_type ?: 'application/octet-stream'),
                (string) $unit->document->filename,
                (string) $unit->claim_token,
                (int) $unit->attempt_count,
                (int) $unit->document->session->state_version,
                $unit->document->session->status->value,
                $pageId,
                function () use ($claim): bool {
                    $now = now()->toDateTimeImmutable();

                    return $this->renew(
                        $claim,
                        $now,
                        $now->modify(sprintf('+%d seconds', \App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding\SheetAnalysisLeasePolicy::UNIT_LEASE_SECONDS)),
                    );
                },
                processingAttemptId: $this->processingAttemptId($unit),
            );
        }, 3);
    }

    private function processingAttemptId(EstimateGenerationProcessingUnit $unit): string
    {
        $metadata = is_array($unit->metadata) ? $unit->metadata : [];
        $attemptId = $metadata['processing_attempt_id'] ?? null;

        if (! is_string($attemptId) || trim($attemptId) === '') {
            $documentMeta = $unit->document instanceof EstimateGenerationDocument && is_array($unit->document->meta)
                ? $unit->document->meta
                : [];
            $attemptId = $documentMeta['processing_attempt_id'] ?? null;
        }

        return is_string($attemptId) && trim($attemptId) !== ''
            ? trim($attemptId)
            : 'legacy-unit-'.(int) $unit->getKey();
    }

    public function claim(int $unitId, string $sourceVersion, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt, int $maxAttempts): DocumentProcessingUnitClaim
    {
        return $this->database->transaction(function () use ($unitId, $sourceVersion, $now, $leaseExpiresAt, $maxAttempts): DocumentProcessingUnitClaim {
            $scope = $this->query()->find($unitId);
            if (! $scope instanceof EstimateGenerationProcessingUnit) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Stale);
            }
            $this->documentQuery()
                ->whereKey($scope->document_id)
                ->where('organization_id', $scope->organization_id)
                ->where('project_id', $scope->project_id)
                ->where('session_id', $scope->session_id)
                ->lockForUpdate()
                ->first();
            $unit = $this->query()->with('document')->lockForUpdate()->find($unitId);

            if (! $this->isCurrent($unit, $sourceVersion)) {
                if ($unit instanceof EstimateGenerationProcessingUnit && $unit->status !== DocumentProcessingUnitStatus::Completed) {
                    $unit->forceFill(['status' => DocumentProcessingUnitStatus::Superseded, 'claim_token' => null, 'lease_expires_at' => null])->save();
                }

                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Stale);
            }

            if (! $unit->document instanceof EstimateGenerationDocument
                || (string) $unit->document->processing_control_status !== 'active'
                || ! hash_equals((string) $unit->document->source_version, $sourceVersion)) {
                if ($unit->status !== DocumentProcessingUnitStatus::Completed) {
                    $unit->forceFill([
                        'status' => DocumentProcessingUnitStatus::Superseded,
                        'claim_token' => null,
                        'lease_expires_at' => null,
                        'next_dispatch_at' => null,
                    ])->save();
                }

                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Stale);
            }

            if ($unit->status === DocumentProcessingUnitStatus::Completed) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::AlreadyCompleted);
            }

            if ($unit->status === DocumentProcessingUnitStatus::Running && $unit->lease_expires_at?->toDateTimeImmutable() > $now) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Busy, busyUntil: $unit->lease_expires_at->toDateTimeImmutable());
            }

            if ((int) $unit->attempt_count >= $maxAttempts || $leaseExpiresAt <= $now) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Exhausted);
            }

            $attemptId = is_string(((array) $unit->metadata)['processing_attempt_id'] ?? null)
                ? ((array) $unit->metadata)['processing_attempt_id']
                : null;
            $scopeQuery = $this->query()
                ->where('organization_id', $unit->organization_id)
                ->where('project_id', $unit->project_id)
                ->where('session_id', $unit->session_id)
                ->where('document_id', $unit->document_id)
                ->where('source_version', $unit->source_version)
                ->where($this->attemptLineagePredicate($attemptId));
            $runningCount = (clone $scopeQuery)
                ->where('status', DocumentProcessingUnitStatus::Running->value)
                ->where('lease_expires_at', '>', $now)
                ->count();
            $systemicFailureCount = (int) ((clone $scopeQuery)
                ->where('status', DocumentProcessingUnitStatus::Failed->value)
                ->whereIn('failure_code', [
                    'unexpected_internal_failure',
                    'vision_provider_request_rejected',
                    'vision_http_failed',
                    'vision_operation_settings_failed',
                    'vision_physical_claim_failed',
                    'vision_physical_attempt_persistence_failed',
                ])
                ->selectRaw('count(*) as aggregate')
                ->groupBy('failure_fingerprint')
                ->orderByDesc('aggregate')
                ->value('aggregate') ?? 0);
            if ($systemicFailureCount >= self::SYSTEMIC_FAILURE_THRESHOLD) {
                return new DocumentProcessingUnitClaim($unitId, DocumentProcessingUnitClaimStatus::Exhausted);
            }
            if ($systemicFailureCount + $runningCount >= self::SYSTEMIC_FAILURE_THRESHOLD) {
                return new DocumentProcessingUnitClaim(
                    $unitId,
                    DocumentProcessingUnitClaimStatus::Busy,
                    busyUntil: $now->modify('+5 seconds'),
                );
            }

            $token = (string) Str::uuid();
            $unit->forceFill([
                'status' => DocumentProcessingUnitStatus::Running,
                'attempt_count' => (int) $unit->attempt_count + 1,
                'claim_token' => $token,
                'lease_expires_at' => $leaseExpiresAt,
                'started_at' => $now,
                'failed_at' => null,
                'failure_code' => null,
                'failure_fingerprint' => null,
                'metadata' => array_diff_key((array) $unit->metadata, ['failure_category' => true]),
            ])->save();
            if ($unit->document instanceof EstimateGenerationDocument
                && in_array((string) $unit->document->status, ['uploaded', 'queued', 'processing'], true)) {
                $unit->document->forceFill([
                    'status' => 'processing',
                    'processing_stage' => 'preflight',
                    'progress_percent' => max(10, (int) $unit->document->progress_percent),
                    'ocr_started_at' => $unit->document->ocr_started_at ?? $now,
                ])->save();
            }

            return new DocumentProcessingUnitClaim(
                $unitId,
                DocumentProcessingUnitClaimStatus::Acquired,
                $token,
                organizationId: (int) $unit->organization_id,
                projectId: (int) $unit->project_id,
                sessionId: (int) $unit->session_id,
                documentId: (int) $unit->document_id,
                sourceVersion: (string) $unit->source_version,
            );
        }, 3);
    }

    public function complete(DocumentProcessingUnitClaim $claim, string $outputVersion, int $outputCount, DateTimeImmutable $now): bool
    {
        return $this->claimQuery($claim)
            ->where('status', DocumentProcessingUnitStatus::Running->value)
            ->where('claim_token', $claim->token)->where('lease_expires_at', '>', $now)
            ->update(['status' => DocumentProcessingUnitStatus::Completed->value, 'output_version' => $outputVersion, 'output_count' => $outputCount, 'claim_token' => null, 'lease_expires_at' => null, 'completed_at' => $now, 'updated_at' => $now]) === 1;
    }

    public function publish(DocumentProcessingUnitClaim $claim, DocumentUnitOutput $output, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($claim, $output, $now): bool {
            $unit = $this->claimQuery($claim)->with('document')->lockForUpdate()->first();

            if (! $unit instanceof EstimateGenerationProcessingUnit
                || ! $this->isCurrent($unit, (string) $unit->source_version)
                || $unit->status !== DocumentProcessingUnitStatus::Running
                || (string) $unit->claim_token !== $claim->token
                || $unit->lease_expires_at?->toDateTimeImmutable() <= $now) {
                return false;
            }

            if ($output->publication !== null) {
                if ($this->publicationWriter === null) {
                    throw new DocumentUnitProcessingException('document_unit_publication_writer_missing');
                }

                return $this->publicationWriter->transaction(
                    (int) $unit->organization_id,
                    (int) $unit->session_id,
                    fn (): bool => $this->publishLocked($unit, $claim, $output, $now),
                );
            }

            return $this->publishLocked($unit, $claim, $output, $now);
        }, 3);
    }

    private function publishLocked(
        EstimateGenerationProcessingUnit $unit,
        DocumentProcessingUnitClaim $claim,
        DocumentUnitOutput $output,
        DateTimeImmutable $now,
    ): bool {

        $page = $this->pageQuery()->where('document_id', $unit->document_id)
            ->where('page_number', $unit->unit_index)->lockForUpdate()->first();
        if (! $page instanceof EstimateGenerationDocumentPage
            || (int) $page->processing_unit_id !== (int) $unit->id
            || (string) $page->source_version !== (string) $unit->source_version
            || $this->pageHasLineage($page)) {
            return false;
        }
        if ($output->publication !== null) {
            $this->publicationWriter?->write(
                $output->publication,
                (int) $unit->organization_id,
                (int) $unit->project_id,
                (int) $unit->session_id,
                (int) $unit->document_id,
                (int) $unit->unit_index,
                (string) $unit->source_version,
            );
        }
        $routing = $output->normalizedPayload['sheet_analysis_routing'] ?? null;
        $targetedReview = is_array($routing) && ($routing['needs_review'] ?? false) === true;
        $semanticState = $output->semanticState();
        $needsReview = $targetedReview || $semanticState !== 'ready';
        $qualityFlags = match (true) {
            $semanticState === 'questions' => ['ai_questions_pending'],
            $semanticState === 'partial' => ['ai_partial_result'],
            $targetedReview => ['targeted_analysis_limited'],
            default => [],
        };
        $page->forceFill(['output_version' => $output->version, 'width' => $output->width, 'height' => $output->height,
            'rotation' => $output->rotation, 'text' => $output->text,
            'text_hash' => $output->text !== '' ? hash('sha256', $output->text) : null,
            'confidence' => $output->confidence, 'normalized_payload' => $output->persistedNormalizedPayload(),
            'quality_flags' => $qualityFlags,
            'status' => $needsReview ? 'needs_review' : 'ready',
            'excluded_at' => null,
            'excluded_reason' => null])->save();

        $published = $this->query()
            ->whereKey($unit->id)
            ->where('organization_id', $unit->organization_id)
            ->where('project_id', $unit->project_id)
            ->where('session_id', $unit->session_id)
            ->where('document_id', $unit->document_id)
            ->where('source_version', $unit->source_version)
            ->where('status', DocumentProcessingUnitStatus::Running->value)
            ->where('claim_token', $claim->token)
            ->where('lease_expires_at', '>', $now)
            ->update([
                'status' => DocumentProcessingUnitStatus::Completed->value,
                'output_version' => $output->version,
                'output_count' => 1,
                'claim_token' => null,
                'lease_expires_at' => null,
                'completed_at' => $now,
                'updated_at' => $now,
            ]) === 1;
        if (! $published) {
            throw new DocumentUnitProcessingException('document_unit_atomic_publication_failed');
        }

        return true;
    }

    public function renew(DocumentProcessingUnitClaim $claim, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): bool
    {
        return $leaseExpiresAt > $now && $this->claimQuery($claim)
            ->where('status', DocumentProcessingUnitStatus::Running->value)
            ->where('claim_token', $claim->token)->where('lease_expires_at', '>', $now)
            ->update(['lease_expires_at' => $leaseExpiresAt, 'updated_at' => $now]) === 1;
    }

    public function pauseForCostConfirmation(DocumentProcessingUnitClaim $claim, DateTimeImmutable $now): bool
    {
        return $this->database->transaction(function () use ($claim, $now): bool {
            $document = $this->documentQuery()
                ->whereKey($claim->documentId)
                ->where('organization_id', $claim->organizationId)
                ->where('project_id', $claim->projectId)
                ->where('session_id', $claim->sessionId)
                ->where('source_version', $claim->sourceVersion)
                ->lockForUpdate()
                ->first();
            if (! $document instanceof EstimateGenerationDocument
                || (string) $document->processing_control_status !== 'paused'
                || (string) $document->processing_control_reason !== 'cost_limit_reached') {
                return false;
            }

            $unit = $this->claimQuery($claim)
                ->where('status', DocumentProcessingUnitStatus::Running->value)
                ->where('claim_token', $claim->token)
                ->where('lease_expires_at', '>', $now)
                ->lockForUpdate()
                ->first();
            if (! $unit instanceof EstimateGenerationProcessingUnit) {
                return false;
            }

            $unitMetadata = is_array($unit->metadata) ? $unit->metadata : [];
            $processingAttemptId = is_string($unitMetadata['processing_attempt_id'] ?? null)
                ? $unitMetadata['processing_attempt_id']
                : null;
            $this->releasePreWireAttempts((int) $unit->id, $processingAttemptId, $now);
            $unit->forceFill([
                'status' => DocumentProcessingUnitStatus::Pending,
                'attempt_count' => max(0, (int) $unit->attempt_count - 1),
                'claim_token' => null,
                'lease_expires_at' => null,
                'next_dispatch_at' => null,
                'failure_code' => null,
                'failure_fingerprint' => null,
                'updated_at' => $now,
            ])->save();
            $updated = true;
            if ($updated) {
                $this->pageQuery()
                    ->where('processing_unit_id', $claim->unitId)
                    ->where('source_version', $claim->sourceVersion)
                    ->where('status', ManageEstimateGenerationDocumentPages::STATUS_PROCESSING)
                    ->update([
                        'status' => ManageEstimateGenerationDocumentPages::STATUS_QUEUED,
                        'updated_at' => $now,
                    ]);
            }

            return $updated;
        }, 3);
    }

    public function fail(
        DocumentProcessingUnitClaim $claim,
        string $code,
        string $fingerprint,
        DateTimeImmutable $now,
        FailureCategory $category = FailureCategory::Recoverable,
        bool $circuitBreaking = false,
        array $resourceUsage = [],
    ): bool {
        return $this->database->transaction(function () use ($claim, $code, $fingerprint, $now, $category, $circuitBreaking, $resourceUsage): bool {
            $document = $this->documentQuery()
                ->whereKey($claim->documentId)
                ->where('organization_id', $claim->organizationId)
                ->where('project_id', $claim->projectId)
                ->where('session_id', $claim->sessionId)
                ->where('source_version', $claim->sourceVersion)
                ->lockForUpdate()
                ->first();
            if (! $document instanceof EstimateGenerationDocument) {
                return false;
            }

            $unit = $this->claimQuery($claim)->lockForUpdate()->first();
            if (! $unit instanceof EstimateGenerationProcessingUnit
                || $unit->status !== DocumentProcessingUnitStatus::Running
                || ! is_string($unit->claim_token)
                || ! is_string($claim->token)
                || ! hash_equals($unit->claim_token, $claim->token)
                || $unit->lease_expires_at === null
                || $unit->lease_expires_at->toDateTimeImmutable() <= $now) {
                return false;
            }

            $updates = [
                'status' => DocumentProcessingUnitStatus::Failed->value,
                'claim_token' => null,
                'lease_expires_at' => null,
                'failure_code' => $code,
                'failure_fingerprint' => $fingerprint,
                'failed_at' => $now,
                'updated_at' => $now,
                'metadata' => [
                    ...(array) $unit->metadata,
                    'failure_category' => $category->value,
                    'actual_execution_count' => (int) $unit->attempt_count,
                    ...($resourceUsage === [] ? [] : ['resource_usage' => $resourceUsage]),
                ],
            ];
            if ($category !== FailureCategory::Recoverable) {
                $updates['attempt_count'] = ProcessDocumentUnit::MAX_ATTEMPTS;
            }
            $unit->forceFill($updates)->save();
            $updated = true;

            if ($updated) {
                $this->pageQuery()
                    ->where('organization_id', $claim->organizationId)
                    ->where('project_id', $claim->projectId)
                    ->where('session_id', $claim->sessionId)
                    ->where('document_id', $claim->documentId)
                    ->where('processing_unit_id', $claim->unitId)
                    ->where('source_version', $claim->sourceVersion)
                    ->where('status', '<>', ManageEstimateGenerationDocumentPages::STATUS_EXCLUDED)
                    ->update([
                        'status' => ManageEstimateGenerationDocumentPages::STATUS_FAILED,
                        'updated_at' => $now,
                    ]);
            }

            $attemptId = is_string(((array) $unit->metadata)['processing_attempt_id'] ?? null)
                ? ((array) $unit->metadata)['processing_attempt_id']
                : null;
            if ($updated && $circuitBreaking
                && $this->systemicFailureCount($claim, $unit, $fingerprint, $attemptId) >= self::SYSTEMIC_FAILURE_THRESHOLD) {
                $document->forceFill([
                    'processing_control_status' => 'cancelled',
                    'processing_control_source_version' => $claim->sourceVersion,
                    'processing_control_attempt_id' => $attemptId,
                    'processing_control_reason' => 'systemic_failure',
                    'processing_control_at' => $now,
                ])->save();
                $stoppableIds = $this->scopedUnitQuery($claim)
                    ->where($this->attemptLineagePredicate($attemptId))
                    ->whereIn('status', [
                        DocumentProcessingUnitStatus::Pending->value,
                        DocumentProcessingUnitStatus::Running->value,
                    ])
                    ->lockForUpdate()
                    ->get(['id', 'status'])
                    ->reject(fn (EstimateGenerationProcessingUnit $candidate): bool => $candidate->status === DocumentProcessingUnitStatus::Running
                        && $this->unitHasWireStarted((int) $candidate->id, $attemptId))
                    ->pluck('id');
                if ($stoppableIds->isNotEmpty()) {
                    foreach ($stoppableIds as $stoppableId) {
                        $this->releasePreWireAttempts((int) $stoppableId, $attemptId, $now);
                    }
                    $this->scopedUnitQuery($claim)
                        ->whereKey($stoppableIds)
                        ->whereIn('status', [
                            DocumentProcessingUnitStatus::Pending->value,
                            DocumentProcessingUnitStatus::Running->value,
                        ])
                        ->update([
                            'status' => DocumentProcessingUnitStatus::Failed->value,
                            'attempt_count' => ProcessDocumentUnit::MAX_ATTEMPTS,
                            'claim_token' => null,
                            'lease_expires_at' => null,
                            'next_dispatch_at' => null,
                            'failure_code' => 'breaker_stopped',
                            'failure_fingerprint' => $fingerprint,
                            'metadata' => $this->terminalFailureMetadataExpression(),
                            'failed_at' => $now,
                            'updated_at' => $now,
                        ]);
                    $this->pageQuery()
                        ->where('organization_id', $claim->organizationId)
                        ->where('project_id', $claim->projectId)
                        ->where('session_id', $claim->sessionId)
                        ->where('document_id', $claim->documentId)
                        ->where('source_version', $claim->sourceVersion)
                        ->whereIn('processing_unit_id', $stoppableIds)
                        ->where('status', '<>', ManageEstimateGenerationDocumentPages::STATUS_EXCLUDED)
                        ->update([
                            'status' => ManageEstimateGenerationDocumentPages::STATUS_FAILED,
                            'updated_at' => $now,
                        ]);
                }
            }

            return $updated;
        }, 3);
    }

    public function supersedeDocumentSource(int $documentId, string $currentSourceVersion): void
    {
        $this->query()->where('document_id', $documentId)->where('source_version', '<>', $currentSourceVersion)
            ->whereNotIn('status', [DocumentProcessingUnitStatus::Completed->value, DocumentProcessingUnitStatus::Superseded->value])
            ->update(['status' => DocumentProcessingUnitStatus::Superseded->value, 'claim_token' => null, 'lease_expires_at' => null, 'updated_at' => now()]);
    }

    private function isCurrent(mixed $unit, string $sourceVersion): bool
    {
        return $unit instanceof EstimateGenerationProcessingUnit
            && $unit->document instanceof EstimateGenerationDocument
            && (string) $unit->source_version === $sourceVersion
            && (string) $unit->document->source_version === $sourceVersion
            && (int) $unit->organization_id === (int) $unit->document->organization_id
            && (int) $unit->project_id === (int) $unit->document->project_id
            && (int) $unit->session_id === (int) $unit->document->session_id
            && $unit->document->status !== 'ignored';
    }

    private function record(EstimateGenerationProcessingUnit $unit): DocumentProcessingUnitRecord
    {
        $failureCategory = FailureCategory::tryFrom((string) (((array) $unit->metadata)['failure_category'] ?? ''));

        return new DocumentProcessingUnitRecord((int) $unit->id, (int) $unit->organization_id, (int) $unit->project_id, (int) $unit->session_id, (int) $unit->document_id, new DocumentUnitData($unit->unit_type, (int) $unit->unit_index, (string) $unit->source_version, (array) $unit->locator), $unit->status, (int) $unit->attempt_count, $unit->claim_token, $unit->lease_expires_at?->toDateTimeImmutable(), $unit->output_version, (int) $unit->output_count, $unit->failure_code, $unit->failure_fingerprint, $failureCategory);
    }

    private function terminalFailureMetadataExpression(): \Illuminate\Database\Query\Expression
    {
        return $this->database->raw(
            "COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('failure_category', 'terminal', 'actual_execution_count', attempt_count)",
        );
    }

    private function unitHasWireStarted(int $unitId, ?string $attemptId): bool
    {
        if ($attemptId === null) {
            return false;
        }

        return $this->database->table('estimate_generation_vision_physical_attempts as attempts')
            ->where('attempts.unit_id', $unitId)
            ->where('attempts.processing_lineage_id', $attemptId)
            ->where(function ($query): void {
                $query->whereIn('attempts.state', ['wire_started', 'response_received'])
                    ->orWhere(function ($completed): void {
                        $completed->where('attempts.state', 'completed')
                            ->whereExists(function ($activeRuns): void {
                                $activeRuns->selectRaw('1')
                                    ->from('estimate_generation_ai_role_runs as active_runs')
                                    ->whereColumn('active_runs.physical_attempt_id', 'attempts.attempt_id')
                                    ->where('active_runs.status', 'running');
                            });
                    });
            })
            ->exists();
    }

    private function releasePreWireAttempts(int $unitId, ?string $attemptId, DateTimeImmutable $now): void
    {
        if ($attemptId === null) {
            return;
        }

        $attemptIds = $this->database->table('estimate_generation_vision_physical_attempts')
            ->where('unit_id', $unitId)
            ->where('processing_lineage_id', $attemptId)
            ->where('state', 'pre_wire')
            ->lockForUpdate()
            ->pluck('attempt_id');
        if ($attemptIds->isEmpty()) {
            return;
        }

        $this->database->table('estimate_generation_ai_role_runs')
            ->whereIn('physical_attempt_id', $attemptIds)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'physical_attempt_id' => null,
                'failure_code' => 'document_cost_limit_paused',
                'owner_uuid' => null,
                'lease_expires_at' => null,
                'failed_at' => $now,
                'updated_at' => $now,
            ]);
        $this->database->table('estimate_generation_vision_physical_attempts')
            ->whereIn('attempt_id', $attemptIds)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('estimate_generation_ai_role_runs')
                    ->whereColumn(
                        'estimate_generation_ai_role_runs.physical_attempt_id',
                        'estimate_generation_vision_physical_attempts.attempt_id',
                    );
            })
            ->delete();
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function query(): Builder
    {
        $model = new EstimateGenerationProcessingUnit;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationDocument> */
    private function documentQuery(): Builder
    {
        $model = new EstimateGenerationDocument;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    /** @return Builder<EstimateGenerationDocumentPage> */
    private function pageQuery(): Builder
    {
        $model = new EstimateGenerationDocumentPage;
        $model->setConnection($this->database->getName());

        return $model->newQuery();
    }

    private function pageIdentity(EstimateGenerationProcessingUnit $unit): int
    {
        $winner = $this->pageQuery()->createOrFirst(
            ['document_id' => $unit->document_id, 'page_number' => $unit->unit_index],
            ['processing_unit_id' => $unit->id, 'source_version' => $unit->source_version,
                'organization_id' => $unit->organization_id, 'project_id' => $unit->project_id,
                'session_id' => $unit->session_id, 'language_codes' => [], 'normalized_payload' => [], 'quality_flags' => [],
                'status' => ManageEstimateGenerationDocumentPages::STATUS_QUEUED],
        );
        $page = $this->pageQuery()->whereKey($winner->getKey())->lockForUpdate()->firstOrFail();
        if ((int) $page->organization_id !== (int) $unit->organization_id
            || (int) $page->project_id !== (int) $unit->project_id
            || (int) $page->session_id !== (int) $unit->session_id
            || (int) $page->document_id !== (int) $unit->document_id) {
            throw new DocumentUnitProcessingException('unit_page_scope_mismatch');
        }
        if ((string) $page->status === ManageEstimateGenerationDocumentPages::STATUS_EXCLUDED) {
            throw new DocumentUnitProcessingException('unit_page_excluded');
        }
        (new DocumentUnitPageReservationPolicy)->assertReservable(
            new DocumentUnitPageReservationState(
                processingUnitId: $page->processing_unit_id !== null ? (int) $page->processing_unit_id : null,
                sourceVersion: $page->source_version !== null ? (string) $page->source_version : null,
                outputVersion: $page->output_version !== null ? (string) $page->output_version : null,
                width: $page->width !== null ? (int) $page->width : null,
                height: $page->height !== null ? (int) $page->height : null,
                rotation: $page->rotation !== null ? (int) $page->rotation : null,
                languageCodes: is_array($page->language_codes) ? $page->language_codes : [],
                text: $page->text !== null ? (string) $page->text : null,
                textHash: $page->text_hash !== null ? (string) $page->text_hash : null,
                confidence: $page->confidence !== null ? (float) $page->confidence : null,
                rawPayloadPath: $page->raw_payload_path !== null ? (string) $page->raw_payload_path : null,
                normalizedPayload: is_array($page->normalized_payload) ? $page->normalized_payload : [],
                qualityFlags: is_array($page->quality_flags) ? $page->quality_flags : [],
                hasLineage: $this->pageHasLineage($page),
            ),
            (int) $unit->id,
            (string) $unit->source_version,
        );
        if ($page->processing_unit_id === null) {
            $page->forceFill(['processing_unit_id' => $unit->id, 'source_version' => $unit->source_version])->save();
        }
        $page->forceFill(['status' => ManageEstimateGenerationDocumentPages::STATUS_PROCESSING])->save();

        return (int) $page->getKey();
    }

    private function pageHasLineage(EstimateGenerationDocumentPage $page): bool
    {
        return $page->facts()->exists() || $page->drawingElements()->exists() || $page->quantityTakeoffs()->exists()
            || $page->scopeInferences()->exists()
            || $this->database->table('estimate_generation_evidence')
                ->where('organization_id', $page->organization_id)->where('project_id', $page->project_id)
                ->where('session_id', $page->session_id)->whereNull('invalidated_at')
                ->where('source_version', (string) $page->source_version)
                ->whereRaw("locator->>'document_id' = ?", [(string) $page->document_id])
                ->whereRaw("locator->>'page' = ?", [(string) $page->page_number])->exists();
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function claimQuery(DocumentProcessingUnitClaim $claim): Builder
    {
        return $this->query()
            ->whereKey($claim->unitId)
            ->where('organization_id', $claim->organizationId)
            ->where('project_id', $claim->projectId)
            ->where('session_id', $claim->sessionId)
            ->where('document_id', $claim->documentId)
            ->where('source_version', $claim->sourceVersion);
    }

    /** @return Builder<EstimateGenerationProcessingUnit> */
    private function scopedUnitQuery(DocumentProcessingUnitClaim $claim): Builder
    {
        return $this->query()
            ->where('organization_id', $claim->organizationId)
            ->where('project_id', $claim->projectId)
            ->where('session_id', $claim->sessionId)
            ->where('document_id', $claim->documentId)
            ->where('source_version', $claim->sourceVersion);
    }

    private function systemicFailureCount(
        DocumentProcessingUnitClaim $claim,
        EstimateGenerationProcessingUnit $unit,
        string $fingerprint,
        ?string $attemptId,
    ): int {
        return $this->scopedUnitQuery($claim)
            ->where($this->attemptLineagePredicate($attemptId))
            ->where('status', DocumentProcessingUnitStatus::Failed->value)
            ->where('failure_fingerprint', $fingerprint)
            ->count();
    }

    private function attemptLineagePredicate(?string $attemptId): \Closure
    {
        return static function (Builder $query) use ($attemptId): void {
            if ($attemptId === null) {
                $query->whereRaw("metadata->>'processing_attempt_id' IS NULL");

                return;
            }

            $query->whereRaw("metadata->>'processing_attempt_id' = ?", [$attemptId]);
        };
    }
}
