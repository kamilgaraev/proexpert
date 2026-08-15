<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Jobs\ProcessEstimateGenerationDocumentJob;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureExecutionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

final class RecoverStalledEstimateGenerationDocuments
{
    public function __construct(
        private readonly DocumentUnitAggregateReconciler $unitAggregates,
        private readonly ReconcileEstimateGenerationDocuments $sessions,
        private readonly DocumentGenerationReadinessService $readiness,
    ) {}

    public function handle(int $minimumAgeSeconds = 120, int $limit = 100): int
    {
        $minimumAge = now()->subSeconds(max(30, $minimumAgeSeconds));
        $documents = EstimateGenerationDocument::query()
            ->with('session')
            ->where('status', 'queued')
            ->where('updated_at', '<=', $minimumAge)
            ->orderBy('id')
            ->limit(max(1, $limit))
            ->get();

        $dispatched = 0;

        foreach ($documents as $document) {
            $session = $document->session;
            if (! $session instanceof EstimateGenerationSession) {
                continue;
            }

            try {
                $sourceVersion = DocumentSourceVersion::fromDocument($document);
            } catch (RuntimeException) {
                continue;
            }

            $meta = is_array($document->meta) ? $document->meta : [];
            $attemptId = $meta['processing_attempt_id'] ?? null;
            if (! is_string($attemptId) || ! Str::isUuid($attemptId)) {
                $attemptId = (string) Str::uuid();
            }

            $document->forceFill([
                'meta' => [
                    ...$meta,
                    'processing_attempt_id' => $attemptId,
                    'recovery_dispatched_at' => now()->toISOString(),
                ],
            ])->saveQuietly();

            ProcessEstimateGenerationDocumentJob::dispatch(
                (int) $document->getKey(),
                FailureExecutionSnapshot::capture(
                    $session,
                    'document_manifest_recovery',
                    attemptId: $attemptId,
                    documentId: (int) $document->getKey(),
                    sourceVersion: $sourceVersion,
                ),
            )
                ->onConnection(ProcessEstimateGenerationDocumentJob::CONNECTION)
                ->onQueue(ProcessEstimateGenerationDocumentJob::RECOVERY_QUEUE);

            $dispatched++;
        }

        $reconciled = $this->recoverReconciliation($minimumAge, max(1, $limit));

        if ($dispatched > 0 || $reconciled > 0) {
            Log::info('[EstimateGeneration] Recovered stalled document jobs', [
                'count' => $dispatched,
                'reconciled_count' => $reconciled,
            ]);
        }

        return $dispatched + $reconciled;
    }

    private function recoverReconciliation(\DateTimeInterface $minimumAge, int $limit): int
    {
        $documents = EstimateGenerationDocument::query()
            ->where(static function ($query): void {
                $query->whereIn('status', ['ready', 'needs_review'])
                    ->orWhere(static function ($cancelled): void {
                        $cancelled->where('status', 'processing')
                            ->where('processing_control_status', 'cancelled');
                    });
            })
            ->whereNotNull('source_version')
            ->where('updated_at', '<=', $minimumAge)
            ->where(function ($query): void {
                $query
                    ->whereNull('units_reconciled_source_version')
                    ->orWhereColumn('units_reconciled_source_version', '<>', 'source_version');
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reconciled = 0;

        foreach ($documents as $document) {
            try {
                $sourceVersion = DocumentSourceVersion::fromDocument($document);
                $this->unitAggregates->reconcile((int) $document->getKey(), $sourceVersion);
                $document->refresh();
                if ((string) $document->units_reconciled_source_version === $sourceVersion) {
                    $reconciled++;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        $sessions = EstimateGenerationSession::query()
            ->with('documents')
            ->where('status', 'processing_documents')
            ->where('updated_at', '<=', $minimumAge)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($sessions as $session) {
            $readiness = $this->readiness->evaluate($session);
            if ((int) ($readiness['summary']['pending_count'] ?? 0) > 0) {
                continue;
            }

            $before = (string) $session->status->value;
            $settled = $this->sessions->reconcile($session);
            if ((string) $settled->status->value !== $before) {
                $reconciled++;
            }
        }

        return $reconciled;
    }
}
