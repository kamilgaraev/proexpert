<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

final readonly class ResetDocumentProcessingUnitsForAttempt
{
    public function handle(
        EstimateGenerationDocument $document,
        string $sourceVersion,
        ?string $oldAttemptId,
        string $attemptId,
    ): int {
        $document->loadMissing(['processingUnits', 'pages']);
        $retryUnitIds = [];
        $preservedReady = 0;

        foreach ($document->processingUnits as $unit) {
            if (! hash_equals($sourceVersion, (string) $unit->source_version)) {
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
            unset($unitMeta['failure_category'], $unitMeta['actual_execution_count']);
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

        foreach ($document->pages as $page) {
            if (! hash_equals($sourceVersion, (string) $page->source_version)
                || ! in_array((int) $page->processing_unit_id, $retryUnitIds, true)) {
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

        return $preservedReady;
    }
}
