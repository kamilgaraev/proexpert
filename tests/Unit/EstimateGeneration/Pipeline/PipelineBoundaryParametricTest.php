<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureWorkflowHandler;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineBoundaryParametricTest extends TestCase
{
    #[DataProvider('stageBoundaries')]
    public function test_every_stage_replays_safely_across_common_crash_boundaries(ProcessingStage $stage, string $boundary): void
    {
        $artifacts = new InMemoryPipelineArtifactStore;
        $state = new InMemoryPipelineStateStore($artifacts);
        $graph = PipelineDefinitionGraph::standard();
        $factory = new StageResultFactory($artifacts, $graph);
        $implementation = new class($stage, $boundary, $factory) implements PipelineStage
        {
            private int $calls = 0;

            public function __construct(private ProcessingStage $stage, private string $boundary, private StageResultFactory $factory) {}

            public function stage(): ProcessingStage
            {
                return $this->stage;
            }

            public function execute(PipelineContext $context): PipelineStageResult
            {
                $this->calls++;
                if ($this->calls === 1 && $this->boundary === 'before_artifact') {
                    throw new RuntimeException('crash');
                }
                $result = $this->factory->make($context, $this->stage, PipelineNineInvocationE2ETest::payload($this->stage));
                if ($this->calls === 1) {
                    throw new RuntimeException('crash');
                }

                return $result;
            }
        };
        $runner = new PipelineRunner(new PipelineRegistry([$implementation]), $state, new FailureRecorder(new class implements FailureStore
        {
            public function record(FailureData $failure, DateTimeImmutable $seenAt): void {}

            public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
            {
                return true;
            }

            public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
            {
                return 0;
            }
        }), new class implements FailureWorkflowHandler
        {
            public function handle(FailureData $failure, ?int $expectedStateVersion = null): void {}
        }, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-11T10:00:00+00:00'));
        $definition = $graph->get($stage);
        $dependencies = [];
        foreach ($definition->dependencies as $dependency) {
            $dependencies[$dependency->value] = 'sha256:'.hash('sha256', $dependency->value);
        }
        $base = 'sha256:'.str_repeat('a', 64);
        $context = new PipelineContext(1, 2, 3, 4, PipelineInputVersion::for($definition, $base, $dependencies), 'generating', generationAttemptId: '00000000-0000-4000-8000-000000000001', baseInputVersion: $base, stage: $stage, dependencyVersions: $dependencies);
        try {
            $runner->runNext($context);
        } catch (RuntimeException) {
        }
        self::assertSame($stage, $runner->runNext($context)?->stage);
        self::assertNull($runner->runNext($context));
    }

    public static function stageBoundaries(): iterable
    {
        foreach (ProcessingStage::cases() as $stage) {
            yield $stage->value.' before artifact' => [$stage, 'before_artifact'];
            yield $stage->value.' after artifact' => [$stage, 'after_artifact'];
        }
    }
}
