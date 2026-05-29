<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;

class DocumentProcessingStatusService
{
    public function markProcessing(EstimateGenerationDocument $document, string $stage, int $progress): void
    {
        $isNewAttempt = (string) $document->status !== 'processing';

        $document->forceFill([
            'status' => 'processing',
            'processing_stage' => $stage,
            'progress_percent' => min(max($progress, 0), 100),
            'ocr_started_at' => $document->ocr_started_at ?? now(),
            'ocr_attempts' => $isNewAttempt ? ((int) $document->ocr_attempts) + 1 : (int) $document->ocr_attempts,
            'error_code' => null,
            'error_message_key' => null,
            'error_context' => null,
        ])->save();
    }

    /**
     * @param array<string, mixed> $factsSummary
     */
    public function markReady(
        EstimateGenerationDocument $document,
        float $qualityScore,
        string $qualityLevel,
        array $factsSummary = []
    ): void {
        $document->forceFill([
            'status' => 'ready',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_score' => $qualityScore,
            'quality_level' => $qualityLevel,
            'facts_summary' => $factsSummary,
            'ocr_finished_at' => now(),
        ])->save();
    }

    /**
     * @param array<int, string> $qualityFlags
     * @param array<string, mixed> $factsSummary
     */
    public function markNeedsReview(
        EstimateGenerationDocument $document,
        float $qualityScore,
        array $qualityFlags,
        array $factsSummary = []
    ): void {
        $document->forceFill([
            'status' => 'needs_review',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'quality_score' => $qualityScore,
            'quality_level' => 'low',
            'quality_flags' => $qualityFlags,
            'facts_summary' => $factsSummary,
            'ocr_finished_at' => now(),
        ])->save();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function markFailed(
        EstimateGenerationDocument $document,
        string $errorCode,
        string $messageKey,
        array $context = []
    ): void {
        $document->forceFill([
            'status' => 'failed',
            'processing_stage' => 'completed',
            'progress_percent' => 100,
            'error_code' => $errorCode,
            'error_message_key' => $messageKey,
            'error_context' => $context,
            'ocr_finished_at' => now(),
        ])->save();
    }
}
