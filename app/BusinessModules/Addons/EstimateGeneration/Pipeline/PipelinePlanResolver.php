<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DateTimeImmutable;

final readonly class PipelinePlanResolver
{
    public function __construct(
        private PipelineDefinitionGraph $graph,
        private PipelineCheckpointStore $checkpoints,
        private PipelineOutputRepository $outputs,
    ) {}

    public function next(PipelineContext $seed, ?PipelinePriorOutputs $prior = null): ?PipelineContext
    {
        $prior ??= $this->outputs->priorOutputs($seed);
        foreach ($this->graph->ordered() as $definition) {
            $dependencies = [];
            foreach ($definition->dependencies as $dependency) {
                $output = $prior->get($dependency);
                if (! $output instanceof PipelineStageOutput) {
                    return null;
                }
                $dependencies[$dependency->value] = $output->version;
            }
            $inputVersion = PipelineInputVersion::for($definition, (string) $seed->baseInputVersion, $dependencies);
            $current = $prior->get($definition->stage);
            if ($current !== null && hash_equals($inputVersion, $current->inputVersion)
                && $current->dependencyVersions === $dependencies) {
                continue;
            }
            if ($current !== null) {
                $this->checkpoints->invalidateDownstream($seed, $definition->stage, new DateTimeImmutable);
                $prior = $this->outputs->priorOutputs($seed);
            }

            return new PipelineContext(
                $seed->sessionId, $seed->organizationId, $seed->projectId, $seed->stateVersion,
                $inputVersion, $seed->sessionStatus, priorOutputs: $prior,
                generationAttemptId: $seed->generationAttemptId, baseInputVersion: $seed->baseInputVersion,
                stage: $definition->stage, dependencyVersions: $dependencies,
            );
        }

        return null;
    }
}
