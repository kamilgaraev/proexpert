<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineArtifactStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\InMemoryPipelineStateStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelinePlanResolver;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStagePayload;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\Stages\StageResultFactory;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

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
}
