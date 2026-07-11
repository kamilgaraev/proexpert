<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

use InvalidArgumentException;

final readonly class PipelineArtifactReference
{
    public function __construct(
        public string $kind,
        public string $objectKey,
        public string $contentVersion,
        public int $bytes,
    ) {
        if (! in_array($kind, ['s3_json_v1', 'memory_json_v1', 'document_manifest_v1'], true)
            || preg_match('/\A[a-zA-Z0-9:._\/-]{1,500}\z/', $objectKey) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/', $contentVersion) !== 1
            || $bytes < 1 || $bytes > PipelineDefinitionGraph::MAX_TOTAL_ARTIFACT_BYTES) {
            throw new InvalidArgumentException('Pipeline artifact reference is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'object_key' => $this->objectKey, 'content_version' => $this->contentVersion, 'bytes' => $this->bytes];
    }

    public static function fromArray(array $data): self
    {
        if (array_keys($data) !== ['kind', 'object_key', 'content_version', 'bytes']
            || ! is_string($data['kind']) || ! is_string($data['object_key'])
            || ! is_string($data['content_version']) || ! is_int($data['bytes'])) {
            throw new InvalidArgumentException('Pipeline artifact reference envelope is invalid.');
        }

        return new self($data['kind'], $data['object_key'], $data['content_version'], $data['bytes']);
    }
}
