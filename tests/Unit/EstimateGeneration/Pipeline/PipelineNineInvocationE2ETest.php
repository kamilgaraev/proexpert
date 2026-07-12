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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineNineInvocationE2ETest extends TestCase
{
    #[Test]
    public function nine_planned_invocations_complete_once_and_redelivery_is_noop(): void
    {
        $artifacts = new InMemoryPipelineArtifactStore;
        $state = new InMemoryPipelineStateStore($artifacts);
        $graph = PipelineDefinitionGraph::standard();
        $results = new StageResultFactory($artifacts, $graph);
        $executions = new \ArrayObject;
        $stages = array_map(static fn (ProcessingStage $stage): PipelineStage => new class($stage, $results, $executions) implements PipelineStage
        {
            public function __construct(private ProcessingStage $value, private StageResultFactory $results, private \ArrayObject $executions) {}

            public function stage(): ProcessingStage
            {
                return $this->value;
            }

            public function execute(PipelineContext $context): PipelineStageResult
            {
                $this->executions[$this->value->value] = ($this->executions[$this->value->value] ?? 0) + 1;

                return $this->results->make($context, $this->value, PipelineNineInvocationE2ETest::payload($this->value));
            }
        }, ProcessingStage::cases());
        $runner = new PipelineRunner(new PipelineRegistry($stages), $state, new FailureRecorder($this->failureStore()), $this->workflow(), static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-11T10:00:00+00:00'));
        $base = 'sha256:'.str_repeat('a', 64);
        $attempt = '00000000-0000-4000-8000-000000000001';
        $outputs = [];
        $lastContext = null;

        foreach ($graph->ordered() as $definition) {
            $dependencies = [];
            foreach ($definition->dependencies as $dependency) {
                $dependencies[$dependency->value] = $outputs[$dependency->value]->version;
            }
            $lastContext = new PipelineContext(1, 2, 3, 4, PipelineInputVersion::for($definition, $base, $dependencies), 'generating', priorOutputs: $state->priorOutputs(new PipelineContext(1, 2, 3, 4, $base, 'generating', generationAttemptId: $attempt, baseInputVersion: $base)), generationAttemptId: $attempt, baseInputVersion: $base, stage: $definition->stage, dependencyVersions: $dependencies);
            $result = $runner->runNext($lastContext);
            self::assertSame($definition->stage, $result?->stage);
            $outputs[$definition->stage->value] = $result->output;
        }
        self::assertSame(9, $state->completedCount());
        self::assertNull($runner->runNext($lastContext));
        self::assertSame(array_fill_keys(array_map(static fn (ProcessingStage $stage): string => $stage->value, ProcessingStage::cases()), 1), $executions->getArrayCopy());
    }

    public static function payload(ProcessingStage $stage): array
    {
        return match ($stage) {
            ProcessingStage::UnderstandDocuments => ['base_input_version' => 'sha256:'.str_repeat('a', 64), 'documents' => [], 'documents_count' => 0, 'rebuild_section_key' => null],
            ProcessingStage::UnderstandObject => ['analysis' => []],
            ProcessingStage::ExtractQuantities => ['quantity_learning_hints' => [], 'building_quantities' => []],
            ProcessingStage::PlanWorkItems => ['object_profile' => [], 'package_plan' => [], 'document_requirements' => [], 'generation_mode' => 'complete', 'regional_context' => [], 'normative_context_pin' => ['status' => 'review_required', 'blocking_issues' => ['normative_dataset_not_pinned']], 'local_estimates' => []],
            ProcessingStage::MatchNormatives, ProcessingStage::AssembleResources, ProcessingStage::ResolvePrices => ['local_estimates' => []],
            ProcessingStage::BuildDraft => ['draft' => []],
            ProcessingStage::ValidateDraft => ['draft' => [], 'requires_review' => true],
        };
    }

    private function failureStore(): FailureStore
    {
        return new class implements FailureStore
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
    }

    private function workflow(): FailureWorkflowHandler
    {
        return new class implements FailureWorkflowHandler
        {
            public function handle(FailureData $failure, ?int $expectedStateVersion = null): void {}
        };
    }
}
