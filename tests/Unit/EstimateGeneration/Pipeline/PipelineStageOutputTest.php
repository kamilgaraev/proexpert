<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineInputVersion;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineStageOutputTest extends TestCase
{
    #[Test]
    public function canonical_reference_output_changes_with_artifact_and_round_trips(): void
    {
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandDocuments);
        $input = PipelineInputVersion::for($definition, 'sha256:'.str_repeat('a', 64), []);
        $left = PipelineStageOutput::create($definition, $input, [], new PipelineArtifactReference('memory_json_v1', 'memory/one', 'sha256:'.str_repeat('b', 64), 10));
        $right = PipelineStageOutput::create($definition, $input, [], new PipelineArtifactReference('memory_json_v1', 'memory/two', 'sha256:'.str_repeat('c', 64), 10));

        self::assertNotSame($left->version, $right->version);
        self::assertSame($left->version, PipelineStageOutput::fromEnvelope($left->envelope(), $left->version)->version);
    }
}
