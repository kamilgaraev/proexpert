<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final class PipelineInputVersion
{
    /** @param array<string, string> $dependencyVersions */
    public static function for(StageDefinition $definition, string $baseInputVersion, array $dependencyVersions): string
    {
        PipelineVersionValidator::assertSha256($baseInputVersion, 'base input');
        $expected = array_map(static fn (ProcessingStage $stage): string => $stage->value, $definition->dependencies);
        if (array_keys($dependencyVersions) !== $expected) {
            throw new InvalidArgumentException('Pipeline dependency version manifest is incomplete or unordered.');
        }
        foreach ($dependencyVersions as $version) {
            PipelineVersionValidator::assertSha256($version, 'dependency output');
        }

        return 'sha256:'.hash('sha256', CanonicalPipelineJson::encode([
            'base_input_version' => $baseInputVersion,
            'stage' => $definition->stage->value,
            'schema_version' => $definition->schemaVersion,
            'dependency_versions' => $dependencyVersions,
        ]));
    }
}
