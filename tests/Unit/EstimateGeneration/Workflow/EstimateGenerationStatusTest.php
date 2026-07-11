<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationAction;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationStatusTest extends TestCase
{
    #[Test]
    public function it_exposes_the_complete_clean_cut_status_contract(): void
    {
        self::assertSame([
            'draft',
            'processing_documents',
            'input_review_required',
            'ready_to_generate',
            'generating',
            'estimate_review_required',
            'ready_to_apply',
            'applying',
            'applied',
            'failed',
            'cancelled',
            'archived',
        ], array_column(EstimateGenerationStatus::cases(), 'value'));

        self::assertSame([
            'upload_documents',
            'start_document_processing',
            'confirm_input',
            'generate',
            'review',
            'apply',
            'retry',
            'cancel',
            'archive',
        ], array_column(EstimateGenerationAction::cases(), 'value'));

        self::assertSame([
            'start_document_processing',
            'documents_ready',
            'documents_need_review',
            'documents_changed',
            'input_confirmed',
            'generation_started',
            'generation_needs_review',
            'generation_ready',
            'review_updated',
            'review_reopened',
            'apply_started',
            'apply_completed',
            'failed',
            'retried',
            'cancelled',
            'archived',
        ], array_column(EstimateGenerationEvent::cases(), 'value'));
    }
}
