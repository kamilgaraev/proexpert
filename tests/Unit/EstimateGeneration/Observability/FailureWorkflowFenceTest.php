<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowAction;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowFence;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FailureWorkflowFenceTest extends TestCase
{
    #[Test]
    public function only_exact_current_claim_can_mutate_workflow(): void
    {
        $fence = new FailureWorkflowFence;
        $failure = $this->failure(FailureCategory::Terminal, 7, 'generating');

        self::assertSame(FailureWorkflowAction::Fail, $fence->decide($failure, 7, EstimateGenerationStatus::Generating));
        self::assertSame(FailureWorkflowAction::None, $fence->decide($failure, 8, EstimateGenerationStatus::Generating));
        self::assertSame(FailureWorkflowAction::None, $fence->decide($failure, 7, EstimateGenerationStatus::ReadyToApply));
        self::assertSame(FailureWorkflowAction::None, $fence->decide($failure, 7, EstimateGenerationStatus::Applied));
    }

    #[Test]
    public function user_action_uses_only_allowed_review_transition_and_recoverable_never_mutates(): void
    {
        $fence = new FailureWorkflowFence;
        self::assertSame(FailureWorkflowAction::ReviewDocuments, $fence->decide(
            $this->failure(FailureCategory::UserActionRequired, 3, 'processing_documents'), 3, EstimateGenerationStatus::ProcessingDocuments,
        ));
        self::assertSame(FailureWorkflowAction::ReviewGeneration, $fence->decide(
            $this->failure(FailureCategory::UserActionRequired, 4, 'generating'), 4, EstimateGenerationStatus::Generating,
        ));
        self::assertSame(FailureWorkflowAction::None, $fence->decide(
            $this->failure(FailureCategory::Recoverable, 4, 'generating'), 4, EstimateGenerationStatus::Generating,
        ));
    }

    private function failure(FailureCategory $category, int $version, string $status): FailureData
    {
        return new FailureData(new FailureContext(
            organizationId: 1, projectId: 2, sessionId: 3, stage: ProcessingStage::BuildDraft,
            operation: 'run_stage', attempt: 1,
            correlationId: '018f4a20-3f4c-7a11-8a22-123456789abc',
            eventId: '018f4a20-3f4c-7a11-8a22-123456789abd',
            expectedSessionStateVersion: $version, expectedSessionStatus: $status,
        ), $category, 'test_failure', []);
    }
}
