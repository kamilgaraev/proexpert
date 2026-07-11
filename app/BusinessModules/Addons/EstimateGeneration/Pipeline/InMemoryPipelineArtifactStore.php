<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use DomainException;

final class InMemoryPipelineArtifactStore implements PipelineArtifactStore
{
    private array $artifacts = [];

    public function write(PipelineContext $context, ProcessingStage $stage, array $data): PipelineStageOutput
    {
        $content = CanonicalPipelineJson::encode($data);
        $version = 'sha256:'.hash('sha256', $content);
        $key = sprintf('memory/%d/%d/%s/%s/%s', $context->organizationId, $context->sessionId, $context->inputVersion, $stage->value, $version);
        $this->artifacts[$key] = $data;

        return PipelineStageOutput::create($stage, 1, ['artifact_kind' => 'memory_json_v1', 'object_key' => $key, 'content_version' => $version, 'bytes' => strlen($content)]);
    }

    public function read(PipelineContext $context, PipelineStageOutput $reference): array
    {
        $key = $reference->data['object_key'] ?? '';
        $prefix = sprintf('memory/%d/%d/%s/', $context->organizationId, $context->sessionId, $context->inputVersion);
        if (! is_string($key) || ! str_starts_with($key, $prefix)) {
            throw new DomainException('Pipeline artifact tenant fence failed.');
        }
        $data = $this->artifacts[$key] ?? null;
        if (! is_array($data)) {
            throw new DomainException('Pipeline artifact is unavailable.');
        }
        $contentVersion = $reference->data['content_version'] ?? '';
        if (! is_string($contentVersion)
            || ! hash_equals($contentVersion, 'sha256:'.hash('sha256', CanonicalPipelineJson::encode($data)))) {
            throw new DomainException('Pipeline artifact integrity check failed.');
        }

        return $data;
    }
}
