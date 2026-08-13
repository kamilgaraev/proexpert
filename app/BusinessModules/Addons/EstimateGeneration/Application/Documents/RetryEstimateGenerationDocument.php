<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RetryEstimateGenerationDocument
{
    public function __construct(
        private EstimateGenerationMutationPolicy $policy,
        private DocumentMutationSessionReconciler $reconciler,
        private DocumentGenerationReadinessService $readiness,
        private AuthorizationService $authorization,
        private ExplicitDocumentRetryEligibility $eligibility,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
        User $actor,
        int $expectedVersion,
        string $expectedSourceVersion,
        string $idempotencyKey,
        ?string $reason,
    ): DocumentActionResult {
        $keyHash = hash('sha256', $idempotencyKey);
        [$lockedSession, $lockedDocument, $attemptId, $disposition, $terminalReplay] = DB::transaction(
            function () use ($session, $document, $actor, $expectedVersion, $expectedSourceVersion, $keyHash, $reason): array {
                $lockedSession = EstimateGenerationSession::query()->lockForUpdate()->findOrFail($session->getKey());
                $lockedDocument = EstimateGenerationDocument::query()
                    ->where('organization_id', $lockedSession->organization_id)
                    ->where('project_id', $lockedSession->project_id)
                    ->where('session_id', $lockedSession->id)
                    ->lockForUpdate()
                    ->findOrFail($document->getKey());
                $lockedDocument->load(['processingUnits', 'pages']);

                if ((int) $actor->current_organization_id !== (int) $lockedDocument->organization_id
                    || ! $this->authorization->can($actor, 'estimate_generation.review', [
                        'organization_id' => (int) $lockedDocument->organization_id,
                        'project_id' => (int) $lockedDocument->project_id,
                    ])) {
                    throw new ExplicitDocumentRetryConflict('forbidden');
                }

                $meta = is_array($lockedDocument->meta) ? $lockedDocument->meta : [];
                $current = is_array($meta['explicit_document_retry'] ?? null) ? $meta['explicit_document_retry'] : [];
                if (! hash_equals((string) $lockedDocument->source_version, $expectedSourceVersion)) {
                    throw new ExplicitDocumentRetryConflict('stale_source');
                }
                try {
                    $storedSourceVersion = DocumentSourceVersion::fromDocument($lockedDocument);
                } catch (\RuntimeException) {
                    throw new ExplicitDocumentRetryConflict('stale_source');
                }
                if (! hash_equals($storedSourceVersion, $expectedSourceVersion)) {
                    throw new ExplicitDocumentRetryConflict('stale_source');
                }
                if (($current['idempotency_hash'] ?? null) === $keyHash) {
                    if (! hash_equals((string) ($current['source_version'] ?? ''), $expectedSourceVersion)) {
                        throw new ExplicitDocumentRetryConflict('stale_source');
                    }

                    return [
                        $lockedSession,
                        $lockedDocument,
                        (string) ($current['attempt_id'] ?? ''),
                        'replayed',
                        ($current['status'] ?? null) !== 'processing',
                    ];
                }
                $historicalAttemptId = $this->historicalAttemptForKey($meta, $keyHash, $expectedSourceVersion);
                if ($historicalAttemptId !== null) {
                    return [$lockedSession, $lockedDocument, $historicalAttemptId, 'replayed', true];
                }
                if (($current['status'] ?? null) === 'processing') {
                    return [
                        $lockedSession,
                        $lockedDocument,
                        (string) ($current['attempt_id'] ?? ''),
                        'already_in_progress',
                        false,
                    ];
                }

                $this->policy->documents($lockedSession, $expectedVersion);
                if (! $this->eligibility->allowed($lockedDocument)) {
                    throw new ExplicitDocumentRetryConflict('retry_not_allowed');
                }

                $oldAttemptId = is_string($meta['processing_attempt_id'] ?? null)
                    ? $meta['processing_attempt_id']
                    : null;
                $attemptId = (string) Str::uuid();
                $requestedAt = now()->toISOString();
                $audit = [
                    'event' => 'estimate_generation.document_explicit_retry_requested',
                    'actor_id' => (int) $actor->getKey(),
                    'organization_id' => (int) $lockedDocument->organization_id,
                    'project_id' => (int) $lockedDocument->project_id,
                    'session_id' => (int) $lockedSession->getKey(),
                    'document_id' => (int) $lockedDocument->getKey(),
                    'old_attempt_id' => $oldAttemptId,
                    'new_attempt_id' => $attemptId,
                    'reason' => 'explicit_retry',
                    'idempotency_hash' => $keyHash,
                    'source_version' => $expectedSourceVersion,
                    'requested_at' => $requestedAt,
                ];

                $retryUnitIds = [];
                $preservedReady = 0;
                foreach ($lockedDocument->processingUnits as $unit) {
                    if (! hash_equals($expectedSourceVersion, (string) $unit->source_version)) {
                        continue;
                    }
                    $unitMeta = is_array($unit->metadata) ? $unit->metadata : [];
                    $unitStatus = $unit->status instanceof DocumentProcessingUnitStatus
                        ? $unit->status->value
                        : (string) $unit->status;
                    if ($unitStatus === DocumentProcessingUnitStatus::Completed->value && (int) $unit->output_count > 0) {
                        $unit->forceFill(['metadata' => [...$unitMeta, 'processing_attempt_id' => $attemptId]])->save();
                        $preservedReady++;

                        continue;
                    }
                    $retryUnitIds[] = (int) $unit->getKey();
                    $failureHistory = is_array($unitMeta['failure_history'] ?? null) ? $unitMeta['failure_history'] : [];
                    if ($unit->failure_code !== null || $unit->failure_fingerprint !== null) {
                        $failureHistory[] = [
                            'attempt_id' => $oldAttemptId,
                            'attempt_count' => (int) $unit->attempt_count,
                            'failure_code' => $unit->failure_code,
                            'failure_fingerprint' => $unit->failure_fingerprint,
                            'failure_category' => $unitMeta['failure_category'] ?? null,
                            'actual_execution_count' => (int) ($unitMeta['actual_execution_count'] ?? min((int) $unit->attempt_count, 1)),
                            'failed_at' => $unit->failed_at?->toISOString(),
                        ];
                    }
                    unset($unitMeta['failure_category']);
                    unset($unitMeta['actual_execution_count']);
                    $unit->forceFill([
                        'status' => DocumentProcessingUnitStatus::Pending->value,
                        'attempt_count' => 0,
                        'claim_token' => null,
                        'lease_expires_at' => null,
                        'output_version' => null,
                        'output_count' => 0,
                        'dispatch_attempt_count' => 0,
                        'last_dispatched_at' => null,
                        'next_dispatch_at' => null,
                        'failure_code' => null,
                        'failure_fingerprint' => null,
                        'metadata' => [
                            ...$unitMeta,
                            'failure_history' => $failureHistory,
                            'processing_attempt_id' => $attemptId,
                        ],
                        'started_at' => null,
                        'completed_at' => null,
                        'failed_at' => null,
                    ])->save();
                }

                foreach ($lockedDocument->pages as $page) {
                    if (! hash_equals($expectedSourceVersion, (string) $page->source_version)) {
                        continue;
                    }
                    if (! in_array((int) $page->processing_unit_id, $retryUnitIds, true)) {
                        continue;
                    }
                    $page->forceFill([
                        'status' => 'queued',
                        'output_version' => null,
                        'text' => null,
                        'text_hash' => null,
                        'confidence' => null,
                        'raw_payload_path' => null,
                        'normalized_payload' => [],
                        'quality_flags' => [],
                        'excluded_at' => null,
                        'excluded_reason' => null,
                        'retry_attempt_id' => $attemptId,
                        'last_retry_requested_at' => now(),
                    ])->save();
                }

                $history = is_array($meta['explicit_document_retry_history'] ?? null)
                    ? $meta['explicit_document_retry_history']
                    : [];
                $history[] = $audit;
                $includedPages = max(0, (int) $lockedDocument->page_count);
                $executionProgress = $includedPages === 0
                    ? 0
                    : (int) floor(($preservedReady / $includedPages) * 100);
                $factsSummary = is_array($lockedDocument->facts_summary) ? $lockedDocument->facts_summary : [];
                $lockedDocument->forceFill([
                    'status' => 'queued',
                    'processing_stage' => 'stored',
                    'progress_percent' => $executionProgress,
                    'ocr_started_at' => null,
                    'ocr_finished_at' => null,
                    'error_code' => null,
                    'error_message_key' => null,
                    'error_context' => null,
                    'processed_page_count' => $preservedReady,
                    'facts_summary' => [
                        ...$factsSummary,
                        'processing_outcome' => [
                            'type' => 'processing',
                            'counts' => [
                                'included' => $includedPages,
                                'ready' => $preservedReady,
                                'needs_user_action' => 0,
                                'terminal_system_failed' => 0,
                                'breaker_stopped' => 0,
                                'system_failed' => 0,
                                'processing' => max(0, $includedPages - $preservedReady),
                                'excluded' => 0,
                            ],
                            'retry_allowed' => false,
                            'execution_progress_percent' => $executionProgress,
                            'readiness' => 'processing',
                            'is_ready' => false,
                        ],
                    ],
                    'units_finalized_source_version' => null,
                    'units_reconciled_source_version' => null,
                    'units_reconcile_claim_token' => null,
                    'units_reconcile_lease_expires_at' => null,
                    'meta' => [
                        ...$meta,
                        'processing_attempt_id' => $attemptId,
                        'explicit_document_retry' => [
                            'idempotency_hash' => $keyHash,
                            'attempt_id' => $attemptId,
                            'source_version' => $expectedSourceVersion,
                            'status' => 'processing',
                            'requested_at' => $requestedAt,
                        ],
                        'explicit_document_retry_history' => $history,
                        'retry_reason' => is_string($reason) && $reason !== '' ? mb_substr($reason, 0, 500) : null,
                    ],
                ])->save();
                EstimateGenerationAuditEvent::query()->create([
                    'session_id' => (int) $lockedSession->getKey(),
                    'package_id' => null,
                    'user_id' => (int) $actor->getKey(),
                    'event_type' => 'document_explicit_retry_requested',
                    'payload' => $audit,
                ]);

                return [$this->reconciler->changed($lockedSession), $lockedDocument, $attemptId, 'accepted', false];
            },
            3,
        );

        if ($disposition === 'accepted') {
            ProcessEstimateGenerationDocumentJob::dispatch(
                (int) $lockedDocument->getKey(),
                FailureExecutionSnapshot::capture(
                    $lockedSession,
                    'document_manifest',
                    attemptId: $attemptId,
                    documentId: (int) $lockedDocument->getKey(),
                    sourceVersion: DocumentSourceVersion::fromDocument($lockedDocument),
                ),
            )->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationDocumentJob::RECOVERY_QUEUE)
                ->afterCommit();
        }

        $lockedSession = $lockedSession->fresh(['documents']) ?? $lockedSession;

        $resultDocument = $lockedDocument->fresh() ?? $lockedDocument;
        $messageKey = $disposition === 'replayed' && $terminalReplay
            ? 'estimate_generation.document_retry_result_replayed'
            : 'estimate_generation.document_retry_queued';

        return new DocumentActionResult(
            $resultDocument,
            $this->readiness->evaluate($lockedSession)['summary'],
            $messageKey,
            $disposition,
            $attemptId,
        );
    }

    /** @param array<string, mixed> $meta */
    private function historicalAttemptForKey(array $meta, string $keyHash, string $sourceVersion): ?string
    {
        $history = is_array($meta['explicit_document_retry_history'] ?? null)
            ? $meta['explicit_document_retry_history']
            : [];

        foreach ($history as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $historicalHash = (string) ($entry['idempotency_hash'] ?? '');
            $historicalSourceVersion = (string) ($entry['source_version'] ?? '');
            $historicalAttemptId = (string) ($entry['new_attempt_id'] ?? '');
            if ($historicalHash !== ''
                && hash_equals($historicalHash, $keyHash)
                && $historicalSourceVersion !== ''
                && hash_equals($historicalSourceVersion, $sourceVersion)
                && $historicalAttemptId !== '') {
                return $historicalAttemptId;
            }
        }

        return null;
    }
}
