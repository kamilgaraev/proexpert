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
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRegistry;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineRunner;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineNineInvocationE2ETest extends TestCase
{
    #[Test]
    public function nine_invocations_complete_nine_outputs_and_replay_is_a_noop(): void
    {
        $artifacts = new InMemoryPipelineArtifactStore;
        $state = new InMemoryPipelineStateStore($artifacts);
        $results = new StageResultFactory($artifacts);
        $executions = [];
        $stages = array_map(static function (ProcessingStage $stage) use ($results, &$executions): PipelineStage {
            return new class($stage, $results, $executions) implements PipelineStage
            {
                public function __construct(private ProcessingStage $value, private StageResultFactory $results, private array &$executions) {}

                public function stage(): ProcessingStage
                {
                    return $this->value;
                }

                public function execute(PipelineContext $context): PipelineStageResult
                {
                    $this->executions[$this->value->value] = ($this->executions[$this->value->value] ?? 0) + 1;

                    return $this->results->make($context, $this->value, ['stage' => $this->value->value]);
                }
            };
        }, ProcessingStage::cases());
        $failureStore = new class implements FailureStore
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
        };
        $workflow = new class implements FailureWorkflowHandler
        {
            public function handle(FailureData $failure, ?int $expectedStateVersion = null): void {}
        };
        $runner = new PipelineRunner(new PipelineRegistry($stages), $state, new FailureRecorder($failureStore), $workflow, static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-11T10:00:00+00:00'));
        $base = new PipelineContext(1, 2, 3, 4, 'attempt-a', 'generating');

        foreach (ProcessingStage::cases() as $expected) {
            $context = $base->withPriorOutputs($state->priorOutputs($base));
            self::assertSame($expected, $runner->runNext($context)?->stage);
        }
        self::assertSame(9, $state->completedCount());
        self::assertNull($runner->runNext($base->withPriorOutputs($state->priorOutputs($base))));
        self::assertSame(array_fill_keys(array_map(static fn (ProcessingStage $stage): string => $stage->value, ProcessingStage::cases()), 1), $executions);
    }
}
