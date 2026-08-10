<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecoveryOutcome;
use App\BusinessModules\Addons\EstimateGeneration\Observability\SimpleFailureRecoveryPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SimpleFailureRecoveryPolicyTest extends TestCase
{
    #[Test]
    public function it_exposes_only_retry_needs_input_and_terminal_outcomes(): void
    {
        $policy = new SimpleFailureRecoveryPolicy;

        self::assertSame(FailureRecoveryOutcome::Retry, $policy->decide(
            $this->failure(FailureCategory::Recoverable), 7, EstimateGenerationStatus::Generating, false,
        ));
        self::assertSame(FailureRecoveryOutcome::NeedsInput, $policy->decide(
            $this->failure(FailureCategory::UserActionRequired), 7, EstimateGenerationStatus::Generating, false,
        ));
        self::assertSame(FailureRecoveryOutcome::Terminal, $policy->decide(
            $this->failure(FailureCategory::Terminal), 7, EstimateGenerationStatus::Generating, false,
        ));
    }

    #[Test]
    public function terminal_failure_preserves_a_usable_draft_and_stale_delivery_is_ignored(): void
    {
        $policy = new SimpleFailureRecoveryPolicy;
        $failure = $this->failure(FailureCategory::Terminal);

        self::assertSame(FailureRecoveryOutcome::NeedsInput, $policy->decide(
            $failure, 7, EstimateGenerationStatus::Generating, true,
        ));
        self::assertSame(FailureRecoveryOutcome::Ignore, $policy->decide(
            $failure, 8, EstimateGenerationStatus::Generating, false,
        ));
        self::assertSame(FailureRecoveryOutcome::Ignore, $policy->decide(
            $failure, 7, EstimateGenerationStatus::ReadyToApply, false,
        ));
    }

    private function failure(FailureCategory $category): FailureData
    {
        return new FailureData(new FailureContext(
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            stage: ProcessingStage::BuildDraft,
            operation: 'run_stage',
            attempt: 1,
            correlationId: '018f4a20-3f4c-7a11-8a22-123456789abc',
            eventId: '018f4a20-3f4c-7a11-8a22-123456789abd',
            expectedSessionStateVersion: 7,
            expectedSessionStatus: 'generating',
        ), $category, 'test_failure', []);
    }
}
