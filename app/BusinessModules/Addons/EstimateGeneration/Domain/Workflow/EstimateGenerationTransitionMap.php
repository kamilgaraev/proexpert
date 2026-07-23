<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

final class EstimateGenerationTransitionMap
{
    /** @var array<string, array<string, string>> */
    private const TRANSITIONS = [
        'draft' => [
            'start_document_processing' => 'processing_documents',
            'cancelled' => 'cancelled',
        ],
        'processing_documents' => [
            'documents_ready' => 'ready_to_generate',
            'documents_need_review' => 'input_review_required',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
        ],
        'input_review_required' => [
            'input_confirmed' => 'ready_to_generate',
            'retried' => 'processing_documents',
            'documents_changed' => 'processing_documents',
            'cancelled' => 'cancelled',
        ],
        'ready_to_generate' => [
            'generation_started' => 'generating',
            'documents_changed' => 'processing_documents',
            'cancelled' => 'cancelled',
        ],
        'generating' => [
            'generation_needs_review' => 'estimate_review_required',
            'generation_ready' => 'ready_to_apply',
            'documents_changed' => 'processing_documents',
            'failed' => 'failed',
            'cancelled' => 'cancelled',
        ],
        'estimate_review_required' => [
            'generation_ready' => 'ready_to_apply',
            'generation_started' => 'generating',
            'documents_changed' => 'processing_documents',
            'review_updated' => 'estimate_review_required',
            'cancelled' => 'cancelled',
        ],
        'ready_to_apply' => [
            'apply_started' => 'applying',
            'generation_started' => 'generating',
            'documents_changed' => 'processing_documents',
            'review_reopened' => 'estimate_review_required',
            'cancelled' => 'cancelled',
        ],
        'applying' => [
            'apply_completed' => 'applied',
            'failed' => 'failed',
        ],
        'failed' => [
            'retried' => '@resume_status',
            'cancelled' => 'cancelled',
            'archived' => 'archived',
        ],
        'cancelled' => [
            'generation_started' => 'generating',
            'archived' => 'archived',
        ],
        'applied' => [
            'generation_started' => 'generating',
            'archived' => 'archived',
        ],
    ];

    public function resolve(
        EstimateGenerationStatus $status,
        EstimateGenerationEvent $event,
        ?EstimateGenerationStatus $resumeStatus = null,
    ): EstimateGenerationStatus {
        $target = self::TRANSITIONS[$status->value][$event->value] ?? null;

        if ($target === null) {
            throw new InvalidEstimateGenerationTransition($status, $event);
        }

        if ($target === '@resume_status') {
            if (! in_array($resumeStatus, [
                EstimateGenerationStatus::ProcessingDocuments,
                EstimateGenerationStatus::Generating,
                EstimateGenerationStatus::Applying,
            ], true)) {
                throw new InvalidEstimateGenerationTransition($status, $event);
            }

            return $resumeStatus === EstimateGenerationStatus::Applying
                ? EstimateGenerationStatus::ReadyToApply
                : $resumeStatus;
        }

        return EstimateGenerationStatus::from($target);
    }
}
