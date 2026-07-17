<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineCheckpointStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineOutputRepository;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePlanResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePriorOutputs;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStagePayload;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PipelinePlanResolverTest extends TestCase
{
    public function test_same_input_is_noop_and_changed_derived_base_invalidates_all_downstream(): void
    {
        $artifacts = new InMemoryPipelineArtifactStore;
        $state = new InMemoryPipelineStateStore($artifacts);
        $graph = PipelineDefinitionGraph::standard();
        $resolver = new PipelinePlanResolver($graph, $state, $state);
        $results = new StageResultFactory($artifacts, $graph);
        $attempt = '00000000-0000-4000-8000-000000000001';
        $base = 'sha256:'.str_repeat('a', 64);
        $seed = new PipelineContext(1, 2, 3, 4, $base, 'generating', generationAttemptId: $attempt, baseInputVersion: $base);

        foreach ($graph->ordered() as $index => $definition) {
            $context = $resolver->next($seed);
            self::assertNotNull($context);
            $now = new DateTimeImmutable('2026-07-11T10:00:00+00:00');
            $claim = $state->claim($context, $definition->stage, $now, $now->modify('+5 minutes'));
            $payload = PipelineNineInvocationE2ETest::payload($definition->stage);
            PipelineStagePayload::from($definition->stage, $payload);
            self::assertTrue($state->complete($claim, $results->make($context->withClaim($claim, $now->modify('+5 minutes')), $definition->stage, $payload), $now));
        }
        self::assertNull($resolver->next($seed));

        $changedBase = 'sha256:'.str_repeat('b', 64);
        $changed = new PipelineContext(1, 2, 3, 4, $changedBase, 'generating', generationAttemptId: $attempt, baseInputVersion: $changedBase);
        self::assertSame(ProcessingStage::UnderstandDocuments, $resolver->next($changed)?->stage);
        self::assertSame(0, $state->completedCount());
    }

    public function test_jsonb_dependency_key_order_does_not_invalidate_a_completed_stage(): void
    {
        $graph = PipelineDefinitionGraph::standard();
        $base = 'sha256:'.str_repeat('a', 64);
        $attempt = '00000000-0000-4000-8000-000000000001';
        $outputs = [];

        foreach (array_slice($graph->ordered(), 0, 4) as $definition) {
            $dependencies = [];
            foreach ($definition->dependencies as $dependency) {
                $dependencies[$dependency->value] = $outputs[$dependency->value]->version;
            }
            $inputVersion = PipelineInputVersion::for($definition, $base, $dependencies);
            if ($definition->stage === ProcessingStage::PlanWorkItems) {
                $dependencies = array_reverse($dependencies, true);
            }
            $outputs[$definition->stage->value] = PipelineStageOutput::create(
                $definition,
                $inputVersion,
                $dependencies,
                new PipelineArtifactReference(
                    'memory_json_v1',
                    'pipeline/test/'.$definition->stage->value,
                    'sha256:'.str_repeat('b', 64),
                    1,
                ),
            );
        }

        $prior = new PipelinePriorOutputs($outputs);
        $checkpoints = new RecordingPipelineCheckpointStore;
        $repository = new class($prior) implements PipelineOutputRepository
        {
            public function __construct(private readonly PipelinePriorOutputs $prior) {}

            public function priorOutputs(PipelineContext $context): PipelinePriorOutputs
            {
                return $this->prior;
            }
        };
        $resolver = new PipelinePlanResolver($graph, $checkpoints, $repository);
        $seed = new PipelineContext(1, 2, 3, 4, $base, 'generating', generationAttemptId: $attempt, baseInputVersion: $base);

        self::assertSame(ProcessingStage::MatchNormatives, $resolver->next($seed)?->stage);
        self::assertSame(0, $checkpoints->invalidations);
    }
}

final class RecordingPipelineCheckpointStore implements PipelineCheckpointStore
{
    public int $invalidations = 0;

    public function claim(PipelineContext $context, ProcessingStage $stage, DateTimeImmutable $now, DateTimeImmutable $leaseExpiresAt): \App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim
    {
        throw new \LogicException('Not used by this test.');
    }

    public function complete(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim $claim, \App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageResult $result, DateTimeImmutable $completedAt): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function renewLease(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim $claim, DateTimeImmutable $now, DateTimeImmutable $newLeaseExpiresAt): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function fail(\App\BusinessModules\Addons\EstimateGeneration\Pipeline\CheckpointClaim $claim, Throwable $error, DateTimeImmutable $failedAt): bool
    {
        throw new \LogicException('Not used by this test.');
    }

    public function invalidateDownstream(PipelineContext $context, ProcessingStage $changedStage, DateTimeImmutable $invalidatedAt): int
    {
        $this->invalidations++;

        return 1;
    }
}
