<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DomainException;

final class InMemoryPipelineArtifactStore implements PipelineArtifactStore
{
    private array $artifacts = [];

    public function write(PipelineContext $context, StageDefinition $definition, array $data): PipelineArtifactReference
    {
        $content = CanonicalPipelineJson::encode($data);
        $version = 'sha256:'.hash('sha256', $content);
        if (strlen($content) > $definition->maxArtifactBytes) {
            throw new DomainException('Pipeline artifact exceeds its stage bound.');
        }
        $key = sprintf('memory/%d/%d/%s/%s/%s', $context->organizationId, $context->sessionId, $context->generationAttemptId ?? 'legacy', $definition->stage->value, $version);
        $this->artifacts[$key] = $data;

        return new PipelineArtifactReference('memory_json_v1', $key, $version, strlen($content));
    }

    public function read(PipelineContext $context, PipelineArtifactReference $reference): array
    {
        $key = $reference->objectKey;
        $prefix = sprintf('memory/%d/%d/%s/', $context->organizationId, $context->sessionId, $context->generationAttemptId ?? 'legacy');
        if (! is_string($key) || ! str_starts_with($key, $prefix)) {
            throw new DomainException('Pipeline artifact tenant fence failed.');
        }
        $data = $this->artifacts[$key] ?? null;
        if (! is_array($data)) {
            throw new DomainException('Pipeline artifact is unavailable.');
        }
        if (! hash_equals($reference->contentVersion, 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($data)))) {
            throw new DomainException('Pipeline artifact integrity check failed.');
        }

        return $data;
    }
}
