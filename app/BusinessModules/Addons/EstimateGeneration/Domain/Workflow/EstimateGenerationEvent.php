<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

enum EstimateGenerationEvent: string
{
    case StartDocumentProcessing = 'start_document_processing';
    case DocumentsReady = 'documents_ready';
    case DocumentsNeedReview = 'documents_need_review';
    case DocumentsChanged = 'documents_changed';
    case InputConfirmed = 'input_confirmed';
    case GenerationStarted = 'generation_started';
    case GenerationNeedsReview = 'generation_needs_review';
    case GenerationReady = 'generation_ready';
    case ReviewUpdated = 'review_updated';
    case ReviewReopened = 'review_reopened';
    case ApplyStarted = 'apply_started';
    case ApplyCompleted = 'apply_completed';
    case Failed = 'failed';
    case Retried = 'retried';
    case Cancelled = 'cancelled';
    case Archived = 'archived';
}
