<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EstimateGenerationApplyBoundaryTest extends TestCase
{
    #[Test]
    public function repeated_apply_returns_the_same_estimate_without_calling_writer_again(): void
    {
        $session = $this->session();
        $writer = new RecordingGeneratedEstimateWriter(781);
        $useCase = $this->useCase($session, $writer);

        $first = $useCase->handle($this->command());
        $second = $useCase->handle($this->command());

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame(781, $first->estimateId);
        self::assertSame($first->estimateId, $second->estimateId);
        self::assertSame(1, $writer->calls);
        self::assertSame(EstimateGenerationStatus::Applied, $session->status);
        self::assertSame(7, $session->state_version);
    }

    #[Test]
    public function foreign_tenant_or_project_is_not_visible_to_apply_use_case(): void
    {
        $session = $this->session();
        $useCase = $this->useCase($session, new RecordingGeneratedEstimateWriter(1));

        $this->expectException(ModelNotFoundException::class);

        $useCase->handle(new ApplyGeneratedEstimateCommand(42, 99, 77, 5));
    }

    #[Test]
    public function stale_expected_version_is_rejected_before_writer_is_called(): void
    {
        $session = $this->session();
        $writer = new RecordingGeneratedEstimateWriter(1);
        $useCase = $this->useCase($session, $writer);

        try {
            $useCase->handle(new ApplyGeneratedEstimateCommand(42, 10, 20, 4));
            self::fail('Expected stale state exception.');
        } catch (StaleEstimateGenerationState) {
            self::assertSame(0, $writer->calls);
            self::assertSame(EstimateGenerationStatus::ReadyToApply, $session->status);
        }
    }

    #[Test]
    public function apply_uses_one_transaction_and_loads_a_scoped_locked_session(): void
    {
        $session = $this->session();
        $writer = new RecordingGeneratedEstimateWriter(2);
        $useCase = $this->useCase($session, $writer);

        $useCase->handle($this->command());

        self::assertSame(1, $useCase->transactions);
        self::assertSame([[42, 10, 20]], $useCase->loads);
    }

    #[Test]
    public function writer_failure_rolls_back_workflow_state(): void
    {
        $session = $this->session();
        $writer = new FailingGeneratedEstimateWriter;
        $useCase = $this->useCase($session, $writer);

        try {
            $useCase->handle($this->command());
            self::fail('Expected writer failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('writer failed', $exception->getMessage());
            self::assertSame(EstimateGenerationStatus::ReadyToApply, $session->status);
            self::assertSame(5, $session->state_version);
            self::assertNull($session->applied_estimate_id);
        }
    }

    #[Test]
    public function controller_delegates_apply_and_contains_no_persistence_or_status_mutation(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/Http/Controllers/EstimateGenerationActionController.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('ApplyGeneratedEstimate $applyGeneratedEstimate', $source);
        self::assertStringContainsString('$this->applyGeneratedEstimate->handle(', $source);
        self::assertStringNotContainsString('$this->draftPersistenceService->apply(', $source);

        $applyMethod = strstr($source, 'public function apply(');
        self::assertIsString($applyMethod);
        $applyMethod = strstr($applyMethod, 'public function selectNormativeCandidate(', true);
        self::assertIsString($applyMethod);
        self::assertStringNotContainsString('DB::transaction', $applyMethod);
        self::assertStringNotContainsString('forceFill(', $applyMethod);
        self::assertStringNotContainsString("'status' =>", $applyMethod);
        self::assertStringContainsString('return AdminResponse::success($result->toArray()', $applyMethod);
        self::assertStringContainsString("'state_version' => ['required', 'integer', 'min:0']", (string) file_get_contents(
            dirname(__DIR__, 3).'/app/BusinessModules/Addons/EstimateGeneration/Http/Requests/ApplyEstimateGenerationDraftRequest.php',
        ));
    }

    private function useCase(
        EstimateGenerationSession $session,
        GeneratedEstimateWriter $writer,
    ): TestableApplyGeneratedEstimate {
        $store = new ApplyInMemoryStateStore($session);

        return new TestableApplyGeneratedEstimate(
            $writer,
            new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, $store),
            $session,
        );
    }

    private function session(): EstimateGenerationSession
    {
        $session = new ApplyTestSession([
            'organization_id' => 10,
            'project_id' => 20,
            'status' => EstimateGenerationStatus::ReadyToApply,
            'state_version' => 5,
        ]);
        $session->id = 42;
        $session->exists = true;

        return $session;
    }

    private function command(): ApplyGeneratedEstimateCommand
    {
        return new ApplyGeneratedEstimateCommand(42, 10, 20, 5);
    }
}

final class ApplyTestSession extends EstimateGenerationSession
{
    public function setAttribute($key, $value)
    {
        if ($key === 'applied_at') {
            $this->attributes[$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }
}

final class TestableApplyGeneratedEstimate extends ApplyGeneratedEstimate
{
    public int $transactions = 0;

    /** @var array<int, array{int, int, int}> */
    public array $loads = [];

    public function __construct(
        GeneratedEstimateWriter $writer,
        EstimateGenerationWorkflow $workflow,
        private EstimateGenerationSession $session,
    ) {
        parent::__construct($writer, $workflow);
    }

    protected function transaction(callable $callback): mixed
    {
        $this->transactions++;
        $snapshot = $this->session->getAttributes();

        try {
            return $callback();
        } catch (\Throwable $exception) {
            $this->session->setRawAttributes($snapshot, true);

            throw $exception;
        }
    }

    protected function loadLockedSession(int $sessionId, int $organizationId, int $projectId): EstimateGenerationSession
    {
        $this->loads[] = [$sessionId, $organizationId, $projectId];

        if (
            $sessionId !== $this->session->getKey()
            || $organizationId !== $this->session->organization_id
            || $projectId !== $this->session->project_id
        ) {
            throw (new ModelNotFoundException)->setModel(EstimateGenerationSession::class, [$sessionId]);
        }

        return $this->session;
    }
}

final class RecordingGeneratedEstimateWriter implements GeneratedEstimateWriter
{
    public int $calls = 0;

    public function __construct(private int $estimateId) {}

    public function createFromSession(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
    ): int {
        $this->calls++;

        return $this->estimateId;
    }
}

final class FailingGeneratedEstimateWriter implements GeneratedEstimateWriter
{
    public function createFromSession(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
    ): int {
        throw new \RuntimeException('writer failed');
    }
}

final class ApplyInMemoryStateStore implements SessionStateStore
{
    public function __construct(private EstimateGenerationSession $session) {}

    public function create(array $attributes): EstimateGenerationSession
    {
        return new EstimateGenerationSession($attributes);
    }

    public function compareAndSet(
        EstimateGenerationSession $session,
        int $expectedVersion,
        EstimateGenerationStatus $status,
        array $attributes,
    ): EstimateGenerationSession {
        if ($session->getKey() !== $this->session->getKey() || $expectedVersion !== $this->session->state_version) {
            throw new StaleEstimateGenerationState((int) $session->getKey(), $expectedVersion);
        }

        $this->session->forceFill([
            ...$attributes,
            'status' => $status,
            'state_version' => $expectedVersion + 1,
        ]);

        return $this->session;
    }
}
