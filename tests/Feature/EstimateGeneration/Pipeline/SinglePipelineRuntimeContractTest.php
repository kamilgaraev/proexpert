<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimate;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\ApplyGeneratedEstimateCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Apply\GeneratedEstimateWriter;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationTransitionMap;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\SessionStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\EloquentPublishDraftOnce;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SinglePipelineRuntimeContractTest extends TestCase
{
    private const PIPELINE_VERSION = 'pipeline:v2';

    public const ARTIFACT_HASH = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function sequential_and_competing_publications_create_one_ordinary_estimate(): void
    {
        $session = $this->session();
        $writer = new PublicationWriter(781);
        $apply = $this->apply($session, $writer);
        $firstPublisher = new TestablePublishDraftOnce($apply, $session);
        $competingPublisher = new TestablePublishDraftOnce($apply, $session);

        $first = $firstPublisher->publish('42', self::PIPELINE_VERSION, self::ARTIFACT_HASH);
        $second = $firstPublisher->publish('42', self::PIPELINE_VERSION, self::ARTIFACT_HASH);
        $competing = $competingPublisher->publish('42', self::PIPELINE_VERSION, self::ARTIFACT_HASH);

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertFalse($competing->created);
        self::assertSame(781, $first->estimateId);
        self::assertSame($first->estimateId, $second->estimateId);
        self::assertSame($first->estimateId, $competing->estimateId);
        self::assertSame(1, $writer->calls);
        self::assertSame(self::PIPELINE_VERSION, $first->pipelineVersion);
        self::assertSame(self::ARTIFACT_HASH, $first->artifactHash);
    }

    #[Test]
    public function failure_before_commit_leaves_no_marker_and_retry_is_safe(): void
    {
        $session = $this->session();
        $failing = new TestablePublishDraftOnce($this->apply($session, new FailingPublicationWriter), $session);

        try {
            $failing->publish('42', self::PIPELINE_VERSION, self::ARTIFACT_HASH);
            self::fail('Expected writer failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('writer failed', $exception->getMessage());
            self::assertNull($session->applied_estimate_id);
            self::assertSame(EstimateGenerationStatus::ReadyToApply, $session->status);
        }

        $writer = new PublicationWriter(991);
        $retried = (new TestablePublishDraftOnce($this->apply($session, $writer), $session))
            ->publish('42', self::PIPELINE_VERSION, self::ARTIFACT_HASH);

        self::assertTrue($retried->created);
        self::assertSame(991, $retried->estimateId);
        self::assertSame(1, $writer->calls);
    }

    #[Test]
    public function runtime_uses_one_pipeline_and_no_finalization_outbox_or_delivery_store(): void
    {
        $root = dirname(__DIR__, 4);
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/EstimateGenerationServiceProvider.php');
        $publisher = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/PublishValidatedDraft.php');

        self::assertIsString($provider);
        self::assertIsString($publisher);
        self::assertStringContainsString('PublishDraftOnce::class', $provider);
        self::assertStringContainsString('PublishDraftOnce $publishDraftOnce', $publisher);

        foreach (['FinalizationOutbox', 'FinalizationDeliveryStore', 'DocumentManifestPublicationFence'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $provider.$publisher);
        }
    }

    private function session(): EstimateGenerationSession
    {
        $session = new PublicationSession([
            'organization_id' => 10,
            'project_id' => 20,
            'status' => EstimateGenerationStatus::ReadyToApply,
            'state_version' => 5,
        ]);
        $session->id = 42;
        $session->exists = true;

        return $session;
    }

    private function apply(EstimateGenerationSession $session, GeneratedEstimateWriter $writer): PublicationApply
    {
        return new PublicationApply(
            $writer,
            new EstimateGenerationWorkflow(new EstimateGenerationTransitionMap, new PublicationStateStore($session)),
            $session,
        );
    }
}

final class PublicationSession extends EstimateGenerationSession
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

final class TestablePublishDraftOnce extends EloquentPublishDraftOnce
{
    public function __construct(ApplyGeneratedEstimate $apply, private EstimateGenerationSession $session)
    {
        parent::__construct($apply);
    }

    protected function loadSession(int $sessionId): EstimateGenerationSession
    {
        if ($sessionId !== $this->session->getKey()) {
            throw new RuntimeException('session not found');
        }

        return $this->session;
    }
}

final class PublicationApply extends ApplyGeneratedEstimate
{
    public function __construct(
        GeneratedEstimateWriter $writer,
        EstimateGenerationWorkflow $workflow,
        private EstimateGenerationSession $session,
    ) {
        parent::__construct($writer, $workflow);
    }

    protected function transaction(callable $callback): mixed
    {
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
        return $this->session;
    }

    protected function replayMatches(int $estimateId, ApplyGeneratedEstimateCommand $command): bool
    {
        return $estimateId === (int) $this->session->applied_estimate_id
            && $command->artifactHash === SinglePipelineRuntimeContractTest::ARTIFACT_HASH;
    }
}

final class PublicationWriter implements GeneratedEstimateWriter
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

    public function publishedMetadata(int $estimateId, int $organizationId, int $projectId): ?array
    {
        return null;
    }
}

final class FailingPublicationWriter implements GeneratedEstimateWriter
{
    public function createFromSession(
        EstimateGenerationSession $session,
        ApplyGeneratedEstimateCommand $command,
    ): int {
        throw new RuntimeException('writer failed');
    }

    public function publishedMetadata(int $estimateId, int $organizationId, int $projectId): ?array
    {
        return null;
    }
}

final class PublicationStateStore implements SessionStateStore
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
