<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationAuditEvent;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocumentPage;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class StopEstimateGenerationDocumentProcessing
{
    public function __construct(
        private AuthorizationService $authorization,
        private DocumentGenerationReadinessService $readiness,
    ) {}

    public function handle(
        EstimateGenerationSession $session,
        EstimateGenerationDocument $document,
        User $actor,
        int $expectedVersion,
        string $expectedSourceVersion,
        string $idempotencyKey,
    ): DocumentActionResult {
        $keyHash = hash('sha256', $idempotencyKey);
        [$lockedSession, $lockedDocument, $disposition, $attemptId] = DB::transaction(function () use (
            $session,
            $document,
            $actor,
            $expectedVersion,
            $expectedSourceVersion,
            $keyHash,
        ): array {
            $lockedSession = EstimateGenerationSession::query()->lockForUpdate()->findOrFail($session->getKey());
            $lockedDocument = EstimateGenerationDocument::query()
                ->where('organization_id', $lockedSession->organization_id)
                ->where('project_id', $lockedSession->project_id)
                ->where('session_id', $lockedSession->id)
                ->lockForUpdate()
                ->findOrFail($document->getKey());
            if ((int) $lockedSession->state_version !== $expectedVersion) {
                throw new DocumentProcessingControlConflict('stale_session');
            }
            if ((int) $actor->current_organization_id !== (int) $lockedDocument->organization_id
                || ! $this->authorization->can($actor, 'estimate_generation.review', [
                    'organization_id' => (int) $lockedDocument->organization_id,
                    'project_id' => (int) $lockedDocument->project_id,
                ])) {
                throw new DocumentProcessingControlConflict('forbidden');
            }
            if (! hash_equals((string) $lockedDocument->source_version, $expectedSourceVersion)) {
                throw new DocumentProcessingControlConflict('stale_source');
            }

            $meta = is_array($lockedDocument->meta) ? $lockedDocument->meta : [];
            $current = is_array($meta['processing_stop'] ?? null) ? $meta['processing_stop'] : [];
            $attemptId = is_string($meta['processing_attempt_id'] ?? null) ? $meta['processing_attempt_id'] : null;
            if (($current['idempotency_hash'] ?? null) === $keyHash) {
                return [$lockedSession, $lockedDocument, 'replayed', $attemptId];
            }
            if ((string) $lockedDocument->processing_control_status === 'cancelled'
                && hash_equals((string) $lockedDocument->processing_control_source_version, $expectedSourceVersion)) {
                return [$lockedSession, $lockedDocument, 'already_stopped', $attemptId];
            }

            $cancelledUnitIds = [];
            $units = EstimateGenerationProcessingUnit::query()
                ->where('organization_id', $lockedDocument->organization_id)
                ->where('project_id', $lockedDocument->project_id)
                ->where('session_id', $lockedDocument->session_id)
                ->where('document_id', $lockedDocument->id)
                ->where('source_version', $expectedSourceVersion)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->get();
            foreach ($units as $unit) {
                if ($unit->status === DocumentProcessingUnitStatus::Running
                    && $this->wireStarted((int) $unit->id, $attemptId)) {
                    continue;
                }
                $this->releasePreWireAttempts((int) $unit->id, $attemptId, now());
                $unitMeta = is_array($unit->metadata) ? $unit->metadata : [];
                $unit->forceFill([
                    'status' => DocumentProcessingUnitStatus::Superseded,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'next_dispatch_at' => null,
                    'metadata' => [
                        ...$unitMeta,
                        'processing_control_status' => 'cancelled',
                        'processing_control_reason' => 'operator_stop',
                    ],
                ])->save();
                $cancelledUnitIds[] = (int) $unit->id;
            }
            if ($cancelledUnitIds !== []) {
                EstimateGenerationDocumentPage::query()
                    ->where('organization_id', $lockedDocument->organization_id)
                    ->where('project_id', $lockedDocument->project_id)
                    ->where('session_id', $lockedDocument->session_id)
                    ->where('document_id', $lockedDocument->id)
                    ->where('source_version', $expectedSourceVersion)
                    ->whereIn('processing_unit_id', $cancelledUnitIds)
                    ->get()
                    ->each(function (EstimateGenerationDocumentPage $page): void {
                        $flags = is_array($page->quality_flags) ? $page->quality_flags : [];
                        $page->forceFill([
                            'status' => 'needs_review',
                            'quality_flags' => array_values(array_unique([...$flags, 'processing_cancelled'])),
                        ])->save();
                    });
            }

            $now = now();
            $explicitRetry = is_array($meta['explicit_document_retry'] ?? null)
                ? $meta['explicit_document_retry']
                : [];
            if (($explicitRetry['status'] ?? null) === 'processing') {
                $explicitRetry = [
                    ...$explicitRetry,
                    'status' => 'cancelled',
                    'terminal_reason' => 'operator_stop',
                    'completed_at' => $now->toISOString(),
                ];
            }
            $hasInFlightUnits = EstimateGenerationProcessingUnit::query()
                ->where('organization_id', $lockedDocument->organization_id)
                ->where('project_id', $lockedDocument->project_id)
                ->where('session_id', $lockedDocument->session_id)
                ->where('document_id', $lockedDocument->id)
                ->where('source_version', $expectedSourceVersion)
                ->whereIn('status', ['pending', 'running'])
                ->exists();
            $lockedDocument->forceFill([
                'status' => $hasInFlightUnits ? 'processing' : 'needs_review',
                'processing_stage' => $hasInFlightUnits ? 'quality_check' : 'completed',
                'error_code' => null,
                'error_message_key' => null,
                'error_context' => null,
                'processing_control_status' => 'cancelled',
                'processing_control_source_version' => $expectedSourceVersion,
                'processing_control_attempt_id' => $attemptId,
                'processing_control_reason' => 'operator_stop',
                'processing_control_at' => $now,
                'meta' => [
                    ...$meta,
                    'explicit_document_retry' => $explicitRetry,
                    'processing_stop' => [
                        'idempotency_hash' => $keyHash,
                        'source_version' => $expectedSourceVersion,
                        'attempt_id' => $attemptId,
                        'stopped_at' => $now->toISOString(),
                    ],
                ],
            ])->save();
            EstimateGenerationAuditEvent::query()->create([
                'session_id' => (int) $lockedSession->id,
                'package_id' => null,
                'user_id' => (int) $actor->id,
                'event_type' => 'document_processing_stopped',
                'payload' => [
                    'document_id' => (int) $lockedDocument->id,
                    'source_version' => $expectedSourceVersion,
                    'attempt_id' => $attemptId,
                    'cancelled_unit_count' => count($cancelledUnitIds),
                ],
            ]);

            return [$lockedSession, $lockedDocument, 'accepted', $attemptId];
        }, 3);

        $freshSession = $lockedSession->fresh(['documents.processingUnits']) ?? $lockedSession;

        return new DocumentActionResult(
            $lockedDocument->fresh() ?? $lockedDocument,
            $this->readiness->evaluate($freshSession)['summary'],
            'estimate_generation.document_processing_stopped',
            $disposition,
            $attemptId,
        );
    }

    private function wireStarted(int $unitId, ?string $attemptId): bool
    {
        if ($attemptId === null) {
            return false;
        }

        return DB::table('estimate_generation_vision_physical_attempts as attempts')
            ->join('estimate_generation_ai_role_runs as runs', function ($join): void {
                $join->on('runs.physical_attempt_id', '=', 'attempts.attempt_id')
                    ->where('runs.status', 'running');
            })
            ->where('attempts.unit_id', $unitId)
            ->where('attempts.processing_lineage_id', $attemptId)
            ->whereIn('attempts.state', ['wire_started', 'response_received'])
            ->exists();
    }

    private function releasePreWireAttempts(int $unitId, ?string $attemptId, \DateTimeInterface $now): void
    {
        if ($attemptId === null) {
            return;
        }
        $attemptIds = DB::table('estimate_generation_vision_physical_attempts')
            ->where('unit_id', $unitId)
            ->where('processing_lineage_id', $attemptId)
            ->where('state', 'pre_wire')
            ->lockForUpdate()
            ->pluck('attempt_id');
        if ($attemptIds->isEmpty()) {
            return;
        }
        DB::table('estimate_generation_ai_role_runs')
            ->whereIn('physical_attempt_id', $attemptIds)
            ->where('status', 'running')
            ->update([
                'status' => 'failed',
                'physical_attempt_id' => null,
                'failure_code' => 'document_processing_stopped',
                'owner_uuid' => null,
                'lease_expires_at' => null,
                'failed_at' => $now,
                'updated_at' => $now,
            ]);
        DB::table('estimate_generation_vision_physical_attempts')
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
}
