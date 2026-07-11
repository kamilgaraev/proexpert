<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransitionEstimateGenerationSessionTest extends TestCase
{
    #[Test]
    public function confirm_cancel_retry_and_archive_use_the_canonical_workflow(): void
    {
        $cases = [
            [EstimateGenerationStatus::InputReviewRequired, EstimateGenerationEvent::InputConfirmed, EstimateGenerationStatus::ReadyToGenerate, null],
            [EstimateGenerationStatus::ReadyToGenerate, EstimateGenerationEvent::Cancelled, EstimateGenerationStatus::Cancelled, null],
            [EstimateGenerationStatus::Failed, EstimateGenerationEvent::Retried, EstimateGenerationStatus::Generating, EstimateGenerationStatus::Generating],
            [EstimateGenerationStatus::Applied, EstimateGenerationEvent::Archived, EstimateGenerationStatus::Archived, null],
        ];

        foreach ($cases as [$from, $event, $to, $resume]) {
            $session = $this->session($from, $resume);
            $store = new TransitionTestStateStore($session);
            $action = new TransitionEstimateGenerationSession(
                new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, $store),
            );

            $result = $action->handle($session, 3, $event);

            self::assertSame($to, $result->status);
            self::assertSame(4, $result->state_version);
        }
    }

    #[Test]
    public function stale_expected_version_is_rejected_before_transition(): void
    {
        $session = $this->session(EstimateGenerationStatus::ReadyToGenerate);
        $action = new TransitionEstimateGenerationSession(new EstimateGenerationWorkflow(
            new EstimateGenerationTransitionMap,
            new TransitionTestStateStore($session),
        ));

        $this->expectException(StaleEstimateGenerationState::class);
        $action->handle($session, 2, EstimateGenerationEvent::Cancelled);
    }

    private function session(EstimateGenerationStatus $status, ?EstimateGenerationStatus $resume = null): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession([
            'status' => $status,
            'state_version' => 3,
            'resume_status' => $resume,
        ]);
        $session->id = 71;
        $session->exists = true;

        return $session;
    }
}

final class TransitionTestStateStore implements SessionStateStore
{
    public function __construct(private EstimateGenerationSession $session) {}

    public function create(array $attributes): EstimateGenerationSession
    {
        return new EstimateGenerationSession($attributes);
    }

    public function compareAndSet(EstimateGenerationSession $session, int $expectedVersion, EstimateGenerationStatus $status, array $attributes): EstimateGenerationSession
    {
        if ($expectedVersion !== $this->session->state_version) {
            throw new StaleEstimateGenerationState((int) $session->getKey(), $expectedVersion);
        }

        $this->session->forceFill([...$attributes, 'status' => $status, 'state_version' => $expectedVersion + 1]);

        return $this->session;
    }
}
