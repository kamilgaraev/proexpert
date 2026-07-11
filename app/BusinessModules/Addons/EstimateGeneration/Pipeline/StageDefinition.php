<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class StageDefinition
{
    /** @param list<ProcessingStage> $dependencies */
    public function __construct(
        public ProcessingStage $stage,
        public int $schemaVersion,
        public array $dependencies,
        public int $maxArtifactBytes,
    ) {
        if ($schemaVersion < 1 || $maxArtifactBytes < 1 || $maxArtifactBytes > PipelineDefinitionGraph::MAX_TOTAL_ARTIFACT_BYTES) {
            throw new InvalidArgumentException('Pipeline stage definition limits are invalid.');
        }
        if (count(array_unique(array_map(static fn (ProcessingStage $dependency): string => $dependency->value, $dependencies))) !== count($dependencies)) {
            throw new InvalidArgumentException('Pipeline stage dependencies must be unique.');
        }
        foreach ($dependencies as $dependency) {
            if ($dependency->order() >= $stage->order()) {
                throw new InvalidArgumentException('Pipeline dependency must precede its stage.');
            }
        }
    }
}
