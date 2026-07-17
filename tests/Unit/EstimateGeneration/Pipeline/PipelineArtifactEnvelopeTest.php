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
        $reference = new PipelineArtifactReference(
            's3_json_v1',
            'org-2/estimate-generation/sessions/1/pipeline/attempts/123e4567-e89b-12d3-a456-426614174000/object.json',
            'sha256:'.str_repeat('c', 64),
            120,
            'version-1',
        );
        $output = PipelineStageOutput::create($definition, $input, $dependencies, $reference);

        self::assertSame($output->version, PipelineStageOutput::fromEnvelope($output->envelope(), $output->version)->version);
        self::assertSame($input, $output->inputVersion);
        self::assertSame($dependencies, $output->dependencyVersions);
        self::assertSame(120, $output->artifact->bytes);
    }

    #[Test]
    public function persisted_output_accepts_jsonb_numeric_strings_without_changing_version(): void
    {
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::UnderstandDocuments);
        $input = 'sha256:'.str_repeat('a', 64);
        $reference = new PipelineArtifactReference(
            's3_json_v1',
            'org-2/estimate-generation/sessions/1/pipeline/attempts/123e4567-e89b-12d3-a456-426614174000/understand_documents.json',
            'sha256:'.str_repeat('c', 64),
            973,
            'version-1',
        );
        $output = PipelineStageOutput::create($definition, $input, [], $reference);
        $stored = $output->envelope();
        $envelope = [
            'stage' => $stored['stage'],
            'artifact' => [
                'kind' => $stored['artifact']['kind'],
                'bytes' => (string) $stored['artifact']['bytes'],
                'object_key' => $stored['artifact']['object_key'],
                'version_id' => $stored['artifact']['version_id'],
                'content_version' => $stored['artifact']['content_version'],
            ],
            'input_version' => $stored['input_version'],
            'schema_version' => (string) $stored['schema_version'],
            'dependency_versions' => $stored['dependency_versions'],
        ];

        $restored = PipelineStageOutput::fromEnvelope($envelope, $output->version);

        self::assertSame($output->version, $restored->version);
        self::assertSame(973, $restored->artifact->bytes);
    }

    #[Test]
    public function dependency_manifest_key_order_does_not_change_output_version(): void
    {
        $definition = PipelineDefinitionGraph::standard()->get(ProcessingStage::BuildDraft);
        $input = 'sha256:'.str_repeat('a', 64);
        $reference = new PipelineArtifactReference('memory_json_v1', 'build-draft', 'sha256:'.str_repeat('c', 64), 973);
        $dependencies = [];
        foreach ($definition->dependencies as $index => $dependency) {
            $dependencies[$dependency->value] = 'sha256:'.str_repeat(dechex($index + 1), 64);
        }

        $ordered = PipelineStageOutput::create($definition, $input, $dependencies, $reference);
        $reversed = PipelineStageOutput::create($definition, $input, array_reverse($dependencies, true), $reference);

        self::assertSame($ordered->version, $reversed->version);
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
