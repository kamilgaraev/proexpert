<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentLifecycleState;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentSystemFailureDetector;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimateGenerationQualityReviewPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use Illuminate\Support\Collection;

class DocumentGenerationReadinessService
{
    private const PENDING_STATUSES = ['uploaded', 'queued', 'processing'];

    private const ACTION_REQUIRED_STATUSES = ['failed', 'needs_review'];

    private const REQUIRED_CAPABILITY_KEYS = [
        'has_room_areas',
        'has_dimensions',
        'has_axes',
        'has_title_block',
        'has_pdf_geometry',
        'has_quantity_takeoffs',
        'has_work_volume_statement_markers',
        'has_specification_markers',
        'has_estimate_markers',
        'has_strong_estimate_markers',
        'requires_cad_geometry_pipeline',
        'requires_manual_review',
    ];

    private EstimateGenerationQualityReviewPolicy $qualityReview;

    private DocumentSystemFailureDetector $systemFailures;

    public function __construct(
        private readonly ?EffectiveSettingsResolver $settingsResolver = null,
        ?EstimateGenerationQualityReviewPolicy $qualityReview = null,
        ?DocumentSystemFailureDetector $systemFailures = null,
    ) {
        $this->qualityReview = $qualityReview ?? new EstimateGenerationQualityReviewPolicy;
        $this->systemFailures = $systemFailures ?? new DocumentSystemFailureDetector;
    }

    /**
     * @return array<string, mixed>
     */
    public function evaluate(EstimateGenerationSession $session): array
    {
        $documents = $session->relationLoaded('documents')
            ? $session->documents
            : $session->documents()->get();
        $hasSystemFailure = $documents->contains(
            fn (EstimateGenerationDocument $document): bool => $this->systemFailures->detected($document),
        );
        $effective = $documents->isEmpty() || $hasSystemFailure
            ? null
            : $this->settingsResolver?->forOperation(
                AiOperationContext::deterministicId(implode('|', [
                    'document-readiness',
                    (string) $session->organization_id,
                    (string) $session->getKey(),
                    (string) $session->state_version,
                ])),
                (int) $session->organization_id,
                (int) $session->getKey(),
            );
        $summary = $this->summary($documents, $effective);
        $reviewAcknowledged = in_array($session->status, [
            EstimateGenerationStatus::ReadyToGenerate,
            EstimateGenerationStatus::EstimateReviewRequired,
            EstimateGenerationStatus::ReadyToApply,
            EstimateGenerationStatus::Applied,
        ], true) || $this->cancelledAfterGeneration($session);
        $canGenerate = $summary['can_generate'] || ($reviewAcknowledged
            && ! $summary['has_pending']
            && (int) $summary['system_failure_count'] === 0
            && collect($summary['items'])->where('status', '!=', 'ignored')->every(
                static fn (array $item): bool => ($item['is_action_required'] ?? false) !== true
                    || (($item['requires_quality_review'] ?? false) === true
                        && ($item['requires_document_review'] ?? false) !== true
                        && ($item['missing_document_understanding'] ?? false) !== true
                        && ($item['has_conflicts'] ?? false) !== true
                        && ($item['has_low_quality'] ?? false) !== true),
            ));
        $summary['review_acknowledged'] = $reviewAcknowledged;
        $summary['can_generate'] = $canGenerate;

        return [
            'can_analyze' => $summary['can_analyze'],
            'can_generate' => $canGenerate,
            'blocking_message_key' => $canGenerate ? null : $this->blockingMessageKey($summary),
            'summary' => $summary,
        ];
    }

    /**
     * @param  Collection<int, EstimateGenerationDocument>  $documents
     * @return array<string, mixed>
     */
    public function summary(Collection $documents, ?EffectiveEstimateGenerationSettings $settings = null): array
    {
        $items = $documents->map(fn (EstimateGenerationDocument $document): array => $this->documentState($document, $settings));
        $pending = $items->where('is_pending', true);
        $failed = $items->where('status', 'failed');
        $systemFailures = $items->where('is_system_failure', true);
        $needsReview = $items->where('status', 'needs_review');
        $ignored = $items->where('status', 'ignored');
        $ready = $items->where('is_ready', true);
        $actionRequired = $items
            ->where('is_action_required', true)
            ->where('status', '!=', 'ignored');
        $conflictDocuments = $items->where('has_conflicts', true)->where('status', '!=', 'ignored');
        $missingUnderstandingDocuments = $items->where('missing_document_understanding', true)->where('status', '!=', 'ignored');
        $understandingReviewDocuments = $items->where('requires_document_review', true)->where('status', '!=', 'ignored');
        $qualityReviewDocuments = $items->where('requires_quality_review', true)->where('status', '!=', 'ignored');
        $lowQualityDocuments = $items->where('has_low_quality', true)->where('status', '!=', 'ignored');
        $structureIncompleteDocuments = $items->where('structure_complete', false)->where('status', '!=', 'ignored');
        $capabilityIncompleteDocuments = $items->where('capabilities_complete', false)->where('status', '!=', 'ignored');
        $actionRequiredCount = $actionRequired->count();
        $hasDocuments = $items->isNotEmpty();
        $multiAgentItems = $items->where('uses_multi_agent_analysis', true);
        $analysisRolesComplete = $multiAgentItems->isNotEmpty()
            && $multiAgentItems->every(static fn (array $item): bool => ($item['analysis_roles_complete'] ?? false) === true);

        return [
            'total' => $items->count(),
            'ready_count' => $ready->count(),
            'pending_count' => $pending->count(),
            'failed_count' => $failed->count(),
            'system_failure_count' => $systemFailures->count(),
            'needs_review_count' => $needsReview->count(),
            'ignored_count' => $ignored->count(),
            'missing_understanding_count' => $missingUnderstandingDocuments->count(),
            'understanding_review_count' => $understandingReviewDocuments->count(),
            'quality_review_count' => $qualityReviewDocuments->count(),
            'low_quality_count' => $lowQualityDocuments->count(),
            'structure_incomplete_count' => $structureIncompleteDocuments->count(),
            'capability_incomplete_count' => $capabilityIncompleteDocuments->count(),
            'action_required_count' => $actionRequiredCount,
            'analysis_roles_complete' => $analysisRolesComplete,
            'multi_agent_document_count' => $multiAgentItems->count(),
            'has_documents' => $hasDocuments,
            'has_pending' => $pending->isNotEmpty(),
            'has_action_required' => $actionRequiredCount > 0,
            'can_analyze' => $hasDocuments && $pending->isEmpty() && $systemFailures->isEmpty(),
            'can_generate' => $hasDocuments
                && $pending->isEmpty()
                && $systemFailures->isEmpty()
                && $actionRequiredCount === 0,
            'problem_flags' => $this->problemFlags($items),
            'statuses' => (object) $items->countBy('status')->all(),
            'items' => $items->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function documentState(
        EstimateGenerationDocument $document,
        ?EffectiveEstimateGenerationSettings $settings,
    ): array {
        $status = (string) ($document->status ?? 'uploaded');
        $isPending = in_array($status, self::PENDING_STATUSES, true);
        $isSystemFailure = $this->systemFailures->detected($document);
        $isActionStatus = in_array($status, self::ACTION_REQUIRED_STATUSES, true) && ! $isSystemFailure;
        $factsSummary = is_array($document->facts_summary) ? $document->facts_summary : [];
        $usesMultiAgentAnalysis = array_key_exists('analysis_roles_complete', $factsSummary);
        $analysisRolesComplete = ($factsSummary['analysis_roles_complete'] ?? false) === true;
        $qualityFlags = is_array($document->quality_flags) ? $document->quality_flags : [];
        $hasConflicts = (is_array($factsSummary['conflicts'] ?? null) && $factsSummary['conflicts'] !== []);
        $hasLowQuality = in_array($document->quality_level, ['low', 'unusable'], true);
        $structureComplete = $isPending || ($usesMultiAgentAnalysis ? $analysisRolesComplete : $this->hasCompleteStructure($document));
        $capabilitiesComplete = $isPending || ($usesMultiAgentAnalysis ? $analysisRolesComplete : $this->hasCompleteCapabilities($factsSummary));
        $missingDocumentUnderstanding = ! $isPending && ($usesMultiAgentAnalysis
            ? ! $analysisRolesComplete
            : $this->missingDocumentUnderstanding($factsSummary));
        $requiresDocumentReview = ! $isPending && ! $usesMultiAgentAnalysis
            && $this->requiresDocumentReview($factsSummary);
        $qualitySignals = is_array($factsSummary['quality_signals'] ?? null) ? $factsSummary['quality_signals'] : [];
        $qualityDecision = $settings === null || $isPending
            ? null
            : $this->qualityReview->decide($settings, $qualitySignals);
        $requiresQualityReview = $qualityDecision?->requiresReview ?? false;

        return [
            'id' => $document->id,
            'filename' => $document->filename,
            'status' => $status,
            'processing_stage' => $document->processing_stage,
            'lifecycle' => DocumentLifecycleState::forDocument($document),
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
            'missing_document_understanding' => $missingDocumentUnderstanding,
            'requires_document_review' => $requiresDocumentReview,
            'uses_multi_agent_analysis' => $usesMultiAgentAnalysis,
            'analysis_roles_complete' => $usesMultiAgentAnalysis && $analysisRolesComplete,
            'requires_quality_review' => $requiresQualityReview,
            'quality_review_reasons' => $qualityDecision?->reasons ?? [],
            'structure_complete' => $structureComplete,
            'capabilities_complete' => $capabilitiesComplete,
            'is_ready' => $status === 'ready' && $structureComplete && $capabilitiesComplete,
            'is_pending' => $isPending,
            'is_system_failure' => $isSystemFailure,
            'is_temporary_failure' => $isSystemFailure && $this->systemFailures->temporary($document),
            'is_action_required' => $isActionStatus
                || (! $isSystemFailure && (
                    $hasLowQuality
                    || (! $isPending && ! $structureComplete)
                    || (! $isPending && ! $capabilitiesComplete)
                    || $missingDocumentUnderstanding
                    || $requiresDocumentReview
                    || $requiresQualityReview
                )),
            'updated_at' => $document->updated_at?->toISOString(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
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

        if ($items->where('missing_document_understanding', true)->where('status', '!=', 'ignored')->isNotEmpty()) {
            $flags[] = 'document_understanding_missing';
        }

        if ($items->where('requires_document_review', true)->where('status', '!=', 'ignored')->isNotEmpty()) {
            $flags[] = 'document_understanding_requires_review';
        }

        if ($items->where('requires_quality_review', true)->where('status', '!=', 'ignored')->isNotEmpty()) {
            $flags[] = 'document_quality_requires_review';
        }

        if ($items->where('status', 'failed')->isNotEmpty()) {
            $flags[] = 'document_processing_failed';
        }

        return $flags;
    }

    /**
     * @param  array<string, mixed>  $factsSummary
     */
    private function missingDocumentUnderstanding(array $factsSummary): bool
    {
        $understanding = is_array($factsSummary['document_understanding'] ?? null)
            ? $factsSummary['document_understanding']
            : [];

        return trim((string) ($understanding['role_for_estimation'] ?? '')) === '';
    }

    private function hasCompleteStructure(EstimateGenerationDocument $document): bool
    {
        $sourceVersion = trim((string) $document->source_version);
        $pageCount = (int) $document->page_count;
        $processedPageCount = (int) $document->processed_page_count;
        $payload = is_array($document->structured_payload) ? $document->structured_payload : [];
        $pages = is_array($payload['pages'] ?? null) ? $payload['pages'] : [];

        return $sourceVersion !== ''
            && (string) $document->units_finalized_source_version === $sourceVersion
            && (string) $document->units_reconciled_source_version === $sourceVersion
            && $pageCount > 0
            && $processedPageCount === $pageCount
            && ($payload['source_version'] ?? null) === $sourceVersion
            && count($pages) === $pageCount;
    }

    /** @param array<string, mixed> $factsSummary */
    private function hasCompleteCapabilities(array $factsSummary): bool
    {
        $understanding = is_array($factsSummary['document_understanding'] ?? null)
            ? $factsSummary['document_understanding']
            : [];
        $capabilities = is_array($understanding['extracted_capabilities'] ?? null)
            ? $understanding['extracted_capabilities']
            : [];

        foreach (self::REQUIRED_CAPABILITY_KEYS as $key) {
            if (! is_bool($capabilities[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function cancelledAfterGeneration(EstimateGenerationSession $session): bool
    {
        return $session->status === EstimateGenerationStatus::Cancelled
            && trim((string) ($session->input_payload['generation_attempt_id'] ?? '')) !== '';
    }

    /**
     * @param  array<string, mixed>  $factsSummary
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
     * @param  array<string, mixed>  $summary
     */
    private function blockingMessageKey(array $summary): ?string
    {
        if (($summary['pending_count'] ?? 0) > 0) {
            return 'estimate_generation.documents_processing';
        }

        if (($summary['system_failure_count'] ?? 0) > 0) {
            return 'estimate_generation.document_processing_system_failed';
        }

        if (($summary['action_required_count'] ?? 0) > 0) {
            return 'estimate_generation.documents_require_action';
        }

        return null;
    }
}
