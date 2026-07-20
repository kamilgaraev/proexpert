<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineDefinitionGraphTest extends TestCase
{
    #[Test]
    public function graph_has_exact_topological_dependencies_and_bounded_total(): void
    {
        $graph = PipelineDefinitionGraph::standard();

        self::assertSame(ProcessingStage::cases(), array_map(static fn ($definition) => $definition->stage, $graph->ordered()));
        self::assertSame([], $graph->get(ProcessingStage::UnderstandDocuments)->dependencies);
        self::assertSame([ProcessingStage::UnderstandDocuments], $graph->get(ProcessingStage::UnderstandObject)->dependencies);
        self::assertSame(7, $graph->get(ProcessingStage::UnderstandObject)->schemaVersion);
        self::assertSame([ProcessingStage::UnderstandObject], $graph->get(ProcessingStage::ExtractQuantities)->dependencies);
        self::assertSame(15, $graph->get(ProcessingStage::ExtractQuantities)->schemaVersion);
        self::assertSame([ProcessingStage::UnderstandObject, ProcessingStage::ExtractQuantities], $graph->get(ProcessingStage::PlanWorkItems)->dependencies);
        self::assertSame(71, $graph->get(ProcessingStage::PlanWorkItems)->schemaVersion);
        self::assertSame([ProcessingStage::PlanWorkItems], $graph->get(ProcessingStage::MatchNormatives)->dependencies);
        self::assertSame(27, $graph->get(ProcessingStage::MatchNormatives)->schemaVersion);
        self::assertSame([ProcessingStage::MatchNormatives], $graph->get(ProcessingStage::AssembleResources)->dependencies);
        self::assertSame(6, $graph->get(ProcessingStage::AssembleResources)->schemaVersion);
        self::assertSame([ProcessingStage::AssembleResources], $graph->get(ProcessingStage::ResolvePrices)->dependencies);
        self::assertSame(13, $graph->get(ProcessingStage::ResolvePrices)->schemaVersion);
        self::assertSame([ProcessingStage::UnderstandDocuments, ProcessingStage::UnderstandObject, ProcessingStage::PlanWorkItems, ProcessingStage::ResolvePrices], $graph->get(ProcessingStage::BuildDraft)->dependencies);
        self::assertSame(2, $graph->get(ProcessingStage::BuildDraft)->schemaVersion);
        self::assertSame(1_966_080, $graph->get(ProcessingStage::BuildDraft)->maxArtifactBytes);
        self::assertSame([ProcessingStage::BuildDraft], $graph->get(ProcessingStage::ValidateDraft)->dependencies);
        self::assertSame(3, $graph->get(ProcessingStage::ValidateDraft)->schemaVersion);
        self::assertSame(1_966_080, $graph->get(ProcessingStage::ValidateDraft)->maxArtifactBytes);
        self::assertSame(10_485_760, array_sum(array_map(static fn ($definition): int => $definition->maxArtifactBytes, $graph->ordered())));
    }

    #[Test]
    public function input_version_changes_with_base_definition_or_exact_dependency_versions(): void
    {
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::BuildDraft);
        $dependencies = [
            ProcessingStage::UnderstandDocuments->value => 'sha256:'.str_repeat('1', 64),
            ProcessingStage::UnderstandObject->value => 'sha256:'.str_repeat('5', 64),
            ProcessingStage::PlanWorkItems->value => 'sha256:'.str_repeat('4', 64),
            ProcessingStage::ResolvePrices->value => 'sha256:'.str_repeat('2', 64),
        ];
        $version = PipelineInputVersion::for($definition, 'sha256:'.str_repeat('a', 64), $dependencies);

        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $version);
        self::assertNotSame($version, PipelineInputVersion::for($definition, 'sha256:'.str_repeat('b', 64), $dependencies));
        self::assertNotSame($version, PipelineInputVersion::for($definition, 'sha256:'.str_repeat('a', 64), [
            ...$dependencies,
            ProcessingStage::ResolvePrices->value => 'sha256:'.str_repeat('3', 64),
        ]));
    }
}
