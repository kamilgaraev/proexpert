<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Workflow;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryableEstimateGenerationSessionRepository;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\RetryEstimateGenerationSessionCommand;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RetryEstimateGenerationSessionTest extends TestCase
{
    #[Test]
    public function generating_retry_rotates_attempt_and_dispatches_exactly_once(): void
    {
        [$action, $repository, $dispatcher] = $this->action($this->failed(EstimateGenerationStatus::Generating));

        $result = $action->handle($this->command());

        self::assertSame(EstimateGenerationStatus::Generating, $result->status);
        self::assertSame('attempt-new', $result->input_payload['generation_attempt_id']);
        self::assertSame([[71, 4, 'attempt-new']], $dispatcher->generation);
        self::assertSame([], $dispatcher->documents);
        self::assertSame([[71, 10, 20]], $repository->locks);
    }

    #[Test]
    public function applying_retry_returns_to_ready_to_apply_without_dispatch(): void
    {
        [$action, , $dispatcher] = $this->action($this->failed(EstimateGenerationStatus::Applying));

        $result = $action->handle($this->command());

        self::assertSame(EstimateGenerationStatus::ReadyToApply, $result->status);
        self::assertSame([], $dispatcher->generation);
        self::assertSame([], $dispatcher->documents);
    }

    #[Test]
    public function document_retry_dispatches_pending_and_failed_documents_once(): void
    {
        $session = $this->failed(EstimateGenerationStatus::ProcessingDocuments);
        $session->setRelation('documents', collect([
            $this->document(1, 'queued'),
            $this->document(2, 'failed'),
            $this->document(3, 'ready'),
        ]));
        [$action, , $dispatcher] = $this->action($session);

        $result = $action->handle($this->command());

        self::assertSame(EstimateGenerationStatus::ProcessingDocuments, $result->status);
        self::assertSame([1, 2], $dispatcher->documents);
        self::assertSame([], $dispatcher->generation);
    }

    #[Test]
    public function document_action_required_returns_to_input_review_without_dispatch(): void
    {
        $session = $this->failed(EstimateGenerationStatus::ProcessingDocuments);
        $session->setRelation('documents', collect([$this->document(4, 'needs_review')]));
        [$action, , $dispatcher] = $this->action($session);

        $result = $action->handle($this->command());

        self::assertSame(EstimateGenerationStatus::InputReviewRequired, $result->status);
        self::assertSame([], $dispatcher->documents);
    }

    #[Test]
    public function ignored_documents_do_not_block_ready_transition(): void
    {
        $session = $this->failed(EstimateGenerationStatus::ProcessingDocuments);
        $session->setRelation('documents', collect([$this->document(5, 'ignored')]));
        [$action, , $dispatcher] = $this->action($session);

        $result = $action->handle($this->command());

        self::assertSame(EstimateGenerationStatus::ReadyToGenerate, $result->status);
        self::assertSame([], $dispatcher->documents);
    }

    #[Test]
    public function ignored_document_is_excluded_while_failed_document_is_dispatched(): void
    {
        $session = $this->failed(EstimateGenerationStatus::ProcessingDocuments);
        $session->setRelation('documents', collect([
            $this->document(6, 'ignored'),
            $this->document(7, 'failed'),
        ]));
        [$action, , $dispatcher] = $this->action($session);

        $result = $action->handle($this->command());

        self::assertSame(EstimateGenerationStatus::ProcessingDocuments, $result->status);
        self::assertSame([7], $dispatcher->documents);
    }

    #[Test]
    public function stale_version_prevents_dispatch(): void
    {
        [$action, , $dispatcher] = $this->action($this->failed(EstimateGenerationStatus::Generating));

        $this->expectException(StaleEstimateGenerationState::class);
        try {
            $action->handle(new RetryEstimateGenerationSessionCommand(71, 10, 20, 2));
        } finally {
            self::assertSame([], $dispatcher->generation);
        }
    }

    /** @return array{RetryEstimateGenerationSession, RetrySessionRepositoryFake, RetryDispatcherFake} */
    private function action(EstimateGenerationSession $session): array
    {
        $store = new RetrySessionStateStore($session);
        $repository = new RetrySessionRepositoryFake($session);
        $dispatcher = new RetryDispatcherFake;

        return [
            new RetryEstimateGenerationSession(
                $repository,
                new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, $store),
                $dispatcher,
                static fn (): string => 'attempt-new',
            ),
            $repository,
            $dispatcher,
        ];
    }

    private function command(): RetryEstimateGenerationSessionCommand
    {
        return new RetryEstimateGenerationSessionCommand(71, 10, 20, 3);
    }

    private function failed(EstimateGenerationStatus $resume): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession([
            'organization_id' => 10,
            'project_id' => 20,
            'status' => EstimateGenerationStatus::Failed,
            'resume_status' => $resume,
            'state_version' => 3,
            'input_payload' => ['generation_attempt_id' => 'attempt-old'],
        ]);
        $session->id = 71;
        $session->exists = true;
        $session->setRelation('documents', collect());

        return $session;
    }

    private function document(int $id, string $status): EstimateGenerationDocument
    {
        $document = new EstimateGenerationDocument(['status' => $status]);
        $document->id = $id;
        $document->exists = true;

        return $document;
    }
}

final class RetrySessionRepositoryFake implements RetryableEstimateGenerationSessionRepository
{
    public array $locks = [];

    public function __construct(private EstimateGenerationSession $session) {}

    public function withLockedSession(int $sessionId, int $organizationId, int $projectId, callable $operation): EstimateGenerationSession
    {
        $this->locks[] = [$sessionId, $organizationId, $projectId];

        return $operation($this->session);
    }
}

final class RetryDispatcherFake implements EstimateGenerationRetryDispatcher
{
    public array $documents = [];

    public array $generation = [];

    public function dispatchDocuments(array $documentIds): void
    {
        $this->documents = $documentIds;
    }

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): void
    {
        $this->generation[] = [$sessionId, $stateVersion, $attemptId];
    }
}

final class RetrySessionStateStore implements SessionStateStore
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
