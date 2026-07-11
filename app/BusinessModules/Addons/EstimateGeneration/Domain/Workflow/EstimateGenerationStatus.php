<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow;

enum EstimateGenerationStatus: string
{
    case Draft = 'draft';
    case ProcessingDocuments = 'processing_documents';
    case InputReviewRequired = 'input_review_required';
    case ReadyToGenerate = 'ready_to_generate';
    case Generating = 'generating';
    case EstimateReviewRequired = 'estimate_review_required';
    case ReadyToApply = 'ready_to_apply';
    case Applying = 'applying';
    case Applied = 'applied';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Archived = 'archived';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Applied, self::Cancelled, self::Archived], true);
    }
}
