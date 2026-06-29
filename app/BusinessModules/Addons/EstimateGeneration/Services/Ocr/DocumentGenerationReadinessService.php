<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Support\Collection;

class DocumentGenerationReadinessService
{
    private const PENDING_STATUSES = ['uploaded', 'queued', 'processing'];

    private const ACTION_REQUIRED_STATUSES = ['failed', 'needs_review'];

    /**
     * @return array<string, mixed>
     */
    public function evaluate(EstimateGenerationSession $session): array
    {
        $documents = $session->relationLoaded('documents')
            ? $session->documents
            : $session->documents()->get();
        $summary = $this->summary($documents);

        return [
            'can_analyze' => $summary['pending_count'] === 0,
            'can_generate' => $summary['pending_count'] === 0 && $summary['action_required_count'] === 0,
            'blocking_message_key' => $this->blockingMessageKey($summary),
            'summary' => $summary,
        ];
    }

    /**
     * @param Collection<int, EstimateGenerationDocument> $documents
     * @return array<string, mixed>
     */
    public function summary(Collection $documents): array
    {
        $items = $documents->map(fn (EstimateGenerationDocument $document): array => $this->documentState($document));
        $pending = $items->where('is_pending', true);
        $failed = $items->where('status', 'failed');
        $needsReview = $items->where('status', 'needs_review');
        $ignored = $items->where('status', 'ignored');
        $ready = $items->where('status', 'ready');
        $actionRequired = $items
            ->where('is_action_required', true)
            ->where('status', '!=', 'ignored');
        $conflictDocuments = $items->where('has_conflicts', true)->where('status', '!=', 'ignored');
        $understandingReviewDocuments = $items->where('requires_document_review', true)->where('status', '!=', 'ignored');
        $lowQualityDocuments = $items->where('has_low_quality', true)->where('status', '!=', 'ignored');
        $actionRequiredCount = $actionRequired->count();

        return [
            'total' => $items->count(),
            'ready_count' => $ready->count(),
            'pending_count' => $pending->count(),
            'failed_count' => $failed->count(),
            'needs_review_count' => $needsReview->count(),
            'ignored_count' => $ignored->count(),
            'understanding_review_count' => $understandingReviewDocuments->count(),
            'action_required_count' => $actionRequiredCount,
            'has_documents' => $items->isNotEmpty(),
            'has_pending' => $pending->isNotEmpty(),
            'has_action_required' => $actionRequiredCount > 0,
            'can_analyze' => $pending->isEmpty(),
            'can_generate' => $pending->isEmpty() && $actionRequiredCount === 0,
            'problem_flags' => $this->problemFlags($items),
            'statuses' => $items->countBy('status')->all(),
            'items' => $items->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentState(EstimateGenerationDocument $document): array
    {
        $status = (string) ($document->status ?? 'uploaded');
        $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
        $qualityFlags = is_array($document->quality_flags) ? $document->quality_flags : [];
        $hasConflicts = (is_array($factsSummary['conflicts'] ?? null) && $factsSummary['conflicts'] !== []);
        $hasLowQuality = in_array($document->quality_level, ['low', 'unusable'], true);
        $requiresDocumentReview = $this->requiresDocumentReview($factsSummary);

        return [
            'id' => $document->id,
            'filename' => $document->filename,
            'status' => $status,
            'processing_stage' => $document->processing_stage,
            'progress_percent' => $document->progress_percent,
            'page_count' => $document->page_count,
            'processed_page_count' => $document->processed_page_count,
            'quality_level' => $document->quality_level,
            'quality_score' => $document->quality_score,
            'quality_flags' => $qualityFlags,
            'error_code' => $document->error_code,
            'error_message_key' => $document->error_message_key,
            'has_conflicts' => $hasConflicts,
            'has_low_quality' => $hasLowQuality,
            'requires_document_review' => $requiresDocumentReview,
            'is_pending' => in_array($status, self::PENDING_STATUSES, true),
            'is_action_required' => in_array($status, self::ACTION_REQUIRED_STATUSES, true) || $hasConflicts || $requiresDocumentReview,
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $items
     * @return array<int, string>
     */
    private function problemFlags(Collection $items): array
    {
        $flags = [];

        if ($items->where('has_conflicts', true)->where('status', '!=', 'ignored')->isNotEmpty()) {
            $flags[] = 'document_fact_conflict';
        }

        if ($items->where('has_low_quality', true)->where('status', '!=', 'ignored')->isNotEmpty()) {
            $flags[] = 'document_low_quality';
        }

        if ($items->where('requires_document_review', true)->where('status', '!=', 'ignored')->isNotEmpty()) {
            $flags[] = 'document_understanding_requires_review';
        }

        if ($items->where('status', 'failed')->isNotEmpty()) {
            $flags[] = 'document_processing_failed';
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $factsSummary
     */
    private function requiresDocumentReview(array $factsSummary): bool
    {
        $understanding = is_array($factsSummary['document_understanding'] ?? null)
            ? $factsSummary['document_understanding']
            : [];
        $capabilities = is_array($understanding['extracted_capabilities'] ?? null)
            ? $understanding['extracted_capabilities']
            : [];

        return ($understanding['role_for_estimation'] ?? null) === 'needs_review'
            || ($capabilities['requires_manual_review'] ?? false) === true;
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function blockingMessageKey(array $summary): ?string
    {
        if (($summary['pending_count'] ?? 0) > 0) {
            return 'estimate_generation.documents_processing';
        }

        if (($summary['action_required_count'] ?? 0) > 0) {
            return 'estimate_generation.documents_require_action';
        }

        return null;
    }
}
