<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\SessionProcessingStopper;
use App\BusinessModules\Addons\EstimateGeneration\Application\Planning\PlanningReanalysisTrigger;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationActionAuthorization;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\TransitionEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Decisions\ActorContext;
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
    protected function setUp(): void
    {
        parent::setUp();
        $container = new \Illuminate\Container\Container;
        $container->instance('config', new \Illuminate\Config\Repository(['estimate-generation' => ['credits' => ['enabled' => false]]]));
        \Illuminate\Container\Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        \Illuminate\Container\Container::setInstance(null);
        parent::tearDown();
    }

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

            $actor = new User(['current_organization_id' => 10, 'is_active' => true]);
            $actor->id = 9;
            $result = $action->handle($session, 3, $event, $actor);

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

    #[Test]
    public function denied_actor_cannot_stop_processing_release_quota_or_change_state(): void
    {
        $session = $this->session(EstimateGenerationStatus::ReadyToGenerate);
        $authorization = $this->createMock(EstimateGenerationActionAuthorization::class);
        $authorization->method('authorize')->willThrowException(new \Illuminate\Auth\Access\AuthorizationException);
        $stopper = $this->createMock(SessionProcessingStopper::class);
        $stopper->expects(self::never())->method('haltForSession');
        $quota = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\Billing\StoppedSessionQuotaReleaser::class);
        $quota->expects(self::never())->method('releaseCancelledReservation');
        $repository = $this->createMock(RetryableEstimateGenerationSessionRepository::class);
        $repository->method('withLockedSession')->willReturnCallback(static fn (int $id, int $organizationId, int $projectId, callable $callback): EstimateGenerationSession => $callback($session));
        $planning = $this->createMock(PlanningReanalysisTrigger::class);
        $planning->expects(self::never())->method('trigger');
        $action = new TransitionEstimateGenerationSession(new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, new TransitionTestStateStore($session)), $authorization, $stopper, $repository, $quota, $planning);
        try {
            $action->handle($session, 3, EstimateGenerationEvent::Cancelled, new User(['current_organization_id' => 10, 'is_active' => true]));
            self::fail('An unauthorized actor changed the session.');
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            self::assertSame(EstimateGenerationStatus::ReadyToGenerate, $session->status);
            self::assertSame(3, $session->state_version);
        }
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
        $quota = $this->createMock(\App\BusinessModules\Addons\EstimateGeneration\Services\Billing\StoppedSessionQuotaReleaser::class);
        $quota->expects($willTransition && in_array($event, [EstimateGenerationEvent::Cancelled, EstimateGenerationEvent::Archived], true)
            ? self::once() : self::never())->method('releaseCancelledReservation')->with(self::identicalTo($session));

        $planning = $this->createMock(PlanningReanalysisTrigger::class);
        $planning->expects($willTransition && $event === EstimateGenerationEvent::InputConfirmed ? self::once() : self::never())
            ->method('trigger')->with(71, self::callback(static fn (ActorContext $context): bool => $context->organizationId === 10
                && $context->projectId === 20 && $context->actorId === 9 && $context->idempotencyKey === 'input-confirmed:71:4'));

        return new TransitionEstimateGenerationSession(new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, new TransitionTestStateStore($session)),
            $authorization, $stopper, $repository, $quota, $planning);
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
