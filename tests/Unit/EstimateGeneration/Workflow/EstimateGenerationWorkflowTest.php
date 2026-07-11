<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\InvalidEstimateGenerationTransition;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationWorkflowTest extends TestCase
{
    #[Test]
    public function generation_started_moves_ready_session_to_generating_and_increments_version(): void
    {
        $store = new InMemorySessionStateStore($this->session(EstimateGenerationStatus::ReadyToGenerate, 4));

        $updated = $this->workflow($store)->transition(
            $store->current(),
            EstimateGenerationEvent::GenerationStarted,
        );

        self::assertSame(EstimateGenerationStatus::Generating, $updated->status);
        self::assertSame(5, $updated->state_version);
    }

    #[Test]
    public function apply_started_is_rejected_before_ready_to_apply(): void
    {
        $store = new InMemorySessionStateStore($this->session(EstimateGenerationStatus::Draft));

        $this->expectException(InvalidEstimateGenerationTransition::class);

        $this->workflow($store)->transition($store->current(), EstimateGenerationEvent::ApplyStarted);
    }

    #[Test]
    public function stale_state_is_rejected_without_changing_persisted_session(): void
    {
        $store = new InMemorySessionStateStore($this->session(EstimateGenerationStatus::ReadyToGenerate, 5));
        $staleSession = $this->session(EstimateGenerationStatus::ReadyToGenerate, 4);

        try {
            $this->workflow($store)->transition($staleSession, EstimateGenerationEvent::GenerationStarted);
            self::fail('Expected stale state exception.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(EstimateGenerationStatus::ReadyToGenerate, $store->current()->status);
            self::assertSame(5, $store->current()->state_version);
        }
    }

    #[Test]
    public function failed_transition_saves_previous_active_status_for_retry(): void
    {
        $store = new InMemorySessionStateStore($this->session(EstimateGenerationStatus::Generating, 2));

        $updated = $this->workflow($store)->transition($store->current(), EstimateGenerationEvent::Failed);

        self::assertSame(EstimateGenerationStatus::Failed, $updated->status);
        self::assertSame(EstimateGenerationStatus::Generating, $updated->resume_status);
        self::assertSame(3, $updated->state_version);
    }

    #[Test]
    public function retried_resumes_allowed_status_and_clears_resume_status(): void
    {
        $store = new InMemorySessionStateStore($this->session(
            EstimateGenerationStatus::Failed,
            7,
            EstimateGenerationStatus::Applying,
        ));

        $updated = $this->workflow($store)->transition($store->current(), EstimateGenerationEvent::Retried);

        self::assertSame(EstimateGenerationStatus::Applying, $updated->status);
        self::assertNull($updated->resume_status);
        self::assertSame(8, $updated->state_version);
    }

    #[Test]
    public function retried_rejects_arbitrary_resume_status_without_changing_session(): void
    {
        $store = new InMemorySessionStateStore($this->session(
            EstimateGenerationStatus::Failed,
            3,
            EstimateGenerationStatus::ReadyToApply,
        ));

        try {
            $this->workflow($store)->transition($store->current(), EstimateGenerationEvent::Retried);
            self::fail('Expected invalid transition exception.');
        } catch (InvalidEstimateGenerationTransition) {
            self::assertSame(EstimateGenerationStatus::Failed, $store->current()->status);
            self::assertSame(3, $store->current()->state_version);
            self::assertSame(EstimateGenerationStatus::ReadyToApply, $store->current()->resume_status);
        }
    }

    #[Test]
    public function transition_persists_supplied_attributes_in_same_version_change(): void
    {
        $store = new InMemorySessionStateStore($this->session(EstimateGenerationStatus::ProcessingDocuments, 1));

        $updated = $this->workflow($store)->transition(
            $store->current(),
            EstimateGenerationEvent::Failed,
            ['last_error' => 'boom'],
        );

        self::assertSame('boom', $updated->last_error);
        self::assertSame(2, $updated->state_version);
    }

    private function workflow(SessionStateStore $store): EstimateGenerationWorkflow
    {
        return new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap(), $store);
    }

    private function session(
        EstimateGenerationStatus $status,
        int $version = 0,
        ?EstimateGenerationStatus $resumeStatus = null,
    ): EstimateGenerationSession {
        $session = new EstimateGenerationSession([
            'status' => $status,
            'state_version' => $version,
            'resume_status' => $resumeStatus,
        ]);
        $session->id = 42;
        $session->exists = true;

        return $session;
    }
}

final class InMemorySessionStateStore implements SessionStateStore
{
    public function __construct(private EstimateGenerationSession $session)
    {
    }

    public function compareAndSet(
        int $sessionId,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): EstimateGenerationSession {
        if ($sessionId !== $this->session->getKey() || $expectedVersion !== $this->session->state_version) {
            throw new StaleEstimateGenerationState($sessionId, $expectedVersion);
        }

        $this->session->forceFill([
            ...$attributes,
            'status' => $status,
            'state_version' => $expectedVersion + 1,
        ]);

        return $this->session;
    }

    public function current(): EstimateGenerationSession
    {
        return $this->session;
    }
}
