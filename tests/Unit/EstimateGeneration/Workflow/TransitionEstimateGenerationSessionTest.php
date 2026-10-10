<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SessionProcessingStopper;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationEvent;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TransitionEstimateGenerationSessionTest extends TestCase
{
    #[Test]
    public function confirmed_input_cancel_and_archive_use_the_canonical_workflow(): void
    {
        $cases = [
            [EstimateGenerationStatus::InputReviewRequired, EstimateGenerationEvent::InputConfirmed, EstimateGenerationStatus::ReadyToGenerate, null],
            [EstimateGenerationStatus::ReadyToGenerate, EstimateGenerationEvent::Cancelled, EstimateGenerationStatus::Cancelled, null],
            [EstimateGenerationStatus::Applied, EstimateGenerationEvent::Archived, EstimateGenerationStatus::Archived, null],
        ];

        foreach ($cases as [$from, $event, $to, $resume]) {
            $session = $this->session($from, $resume);
            $action = $this->action($session, $event);

            $result = $action->handle($session, 3, $event, new User(['current_organization_id' => 10, 'is_active' => true]));

            self::assertSame($to, $result->status);
            self::assertSame(4, $result->state_version);
        }
    }

    #[Test]
    public function stale_expected_version_is_rejected_before_transition(): void
    {
        $session = $this->session(EstimateGenerationStatus::ReadyToGenerate);
        $action = $this->action($session, EstimateGenerationEvent::Cancelled, false);

        $this->expectException(StaleEstimateGenerationState::class);
        $action->handle($session, 2, EstimateGenerationEvent::Cancelled, new User(['current_organization_id' => 10, 'is_active' => true]));
    }

    private function action(EstimateGenerationSession $session, EstimateGenerationEvent $event, bool $willTransition = true): TransitionEstimateGenerationSession
    {
        $authorization = $this->createMock(EstimateGenerationActionAuthorization::class);
        $authorization->expects(self::once())->method('authorize')->with(self::isInstanceOf(User::class), self::identicalTo($session),
            $event === EstimateGenerationEvent::InputConfirmed ? 'estimate_generation.review' : 'estimate_generation.generate');
        $stopper = $this->createMock(SessionProcessingStopper::class);
        $stopper->expects($willTransition && in_array($event, [EstimateGenerationEvent::Cancelled, EstimateGenerationEvent::Archived], true)
            ? self::once() : self::never())->method('haltForSession');
        $repository = $this->createMock(RetryableEstimateGenerationSessionRepository::class);
        $repository->expects(self::once())->method('withLockedSession')->with(71, 10, 20, self::isType('callable'))
            ->willReturnCallback(static fn (int $id, int $organizationId, int $projectId, callable $callback): EstimateGenerationSession => $callback($session));

        return new TransitionEstimateGenerationSession(new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, new TransitionTestStateStore($session)),
            $authorization, $stopper, $repository);
    }

    private function session(EstimateGenerationStatus $status, ?EstimateGenerationStatus $resume = null): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession([
            'organization_id' => 10,
            'project_id' => 20,
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
