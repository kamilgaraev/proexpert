<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationMutationPolicy;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationMutationPolicyTest extends TestCase
{
    #[Test]
    public function active_review_with_exact_version_is_allowed(): void
    {
        $session = $this->session(EstimateGenerationStatus::EstimateReviewRequired, 8);

        (new EstimateGenerationMutationPolicy)->review($session, 8);

        self::assertTrue(true);
    }

    #[Test]
    public function applied_session_can_be_regenerated_with_exact_version(): void
    {
        $session = $this->session(EstimateGenerationStatus::Applied, 8);

        (new EstimateGenerationMutationPolicy)->generate($session, 8);

        self::assertTrue(true);
    }

    #[Test]
    public function stale_version_is_rejected_before_state_policy(): void
    {
        $this->expectException(StaleEstimateGenerationState::class);

        (new EstimateGenerationMutationPolicy)->review(
            $this->session(EstimateGenerationStatus::EstimateReviewRequired, 8),
            7,
        );
    }

    #[Test]
    public function terminal_document_mutation_is_rejected(): void
    {
        $this->expectException(InvalidEstimateGenerationState::class);

        (new EstimateGenerationMutationPolicy)->documents(
            $this->session(EstimateGenerationStatus::Applied, 8),
            8,
        );
    }

    #[Test]
    public function failed_document_processing_session_can_be_recovered_by_document_action(): void
    {
        $session = $this->session(EstimateGenerationStatus::Failed, 8);
        $session->resume_status = EstimateGenerationStatus::ProcessingDocuments;

        (new EstimateGenerationMutationPolicy)->documents($session, 8);

        self::assertTrue(true);
    }

    #[Test]
    public function failed_generation_session_rejects_document_action(): void
    {
        $session = $this->session(EstimateGenerationStatus::Failed, 8);
        $session->resume_status = EstimateGenerationStatus::Generating;

        $this->expectException(InvalidEstimateGenerationState::class);
        (new EstimateGenerationMutationPolicy)->documents($session, 8);
    }

    private function session(EstimateGenerationStatus $status, int $version): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession(['status' => $status, 'state_version' => $version]);
        $session->id = 42;
        $session->exists = true;

        return $session;
    }
}
