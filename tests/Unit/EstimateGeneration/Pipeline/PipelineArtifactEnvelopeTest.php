<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineArtifactReference;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineDefinitionGraph;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageOutput;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PipelineArtifactEnvelopeTest extends TestCase
{
    #[Test]
    public function closed_output_round_trips_exact_stage_schema_input_dependencies_and_reference(): void
    {
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $input = 'sha256:'.str_repeat('a', 64);
        $dependencies = [ProcessingStage::UnderstandDocuments->value => 'sha256:'.str_repeat('b', 64)];
        $reference = new PipelineArtifactReference('s3_json_v1', 'org-2/ai-estimator/sessions/1/attempts/x/object.json', 'sha256:'.str_repeat('c', 64), 120);
        $output = PipelineStageOutput::create($definition, $input, $dependencies, $reference);

        self::assertSame($output->version, PipelineStageOutput::fromEnvelope($output->envelope(), $output->version)->version);
        self::assertSame($input, $output->inputVersion);
        self::assertSame($dependencies, $output->dependencyVersions);
        self::assertSame(120, $output->artifact->bytes);
    }

    #[Test]
    public function wrong_dependency_manifest_is_rejected_before_hydration(): void
    {
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandObject);
        $this->expectException(InvalidArgumentException::class);

        PipelineStageOutput::create(
            $definition,
            'sha256:'.str_repeat('a', 64),
            [],
            new PipelineArtifactReference('s3_json_v1', 'org-2/x', 'sha256:'.str_repeat('c', 64), 120),
        );
    }
}
