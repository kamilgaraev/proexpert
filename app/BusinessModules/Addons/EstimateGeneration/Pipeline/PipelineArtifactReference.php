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
        public ?string $versionId = null,
    ) {
        if (! in_array($kind, ['s3_json_v1', 'memory_json_v1', 'document_manifest_v1'], true)
            || preg_match('/\A[a-zA-Z0-9:._\/-]{1,500}\z/', $objectKey) !== 1
            || preg_match('/\Asha256:[0-9a-f]{64}\z/', $contentVersion) !== 1
            || $bytes < 1 || $bytes > PipelineDefinitionGraph::MAX_TOTAL_ARTIFACT_BYTES
            || ($kind === 's3_json_v1' && (! is_string($versionId)
                || preg_match('/\A[\x21-\x7e]{1,1024}\z/D', $versionId) !== 1))
            || ($kind !== 's3_json_v1' && $versionId !== null)) {
            throw new InvalidArgumentException('Pipeline artifact reference is invalid.');
        }
    }

    public function toArray(): array
    {
        return ['kind' => $this->kind, 'object_key' => $this->objectKey, 'content_version' => $this->contentVersion, 'bytes' => $this->bytes, 'version_id' => $this->versionId];
    }

    public static function fromArray(array $data): self
    {
        $keys = array_keys($data);
        sort($keys, SORT_STRING);
        if ($keys !== ['bytes', 'content_version', 'kind', 'object_key', 'version_id']
            || ! is_string($data['kind']) || ! is_string($data['object_key'])
            || ! is_string($data['content_version']) || ! is_int($data['bytes'])
            || ($data['version_id'] !== null && ! is_string($data['version_id']))) {
            throw new InvalidArgumentException('Pipeline artifact reference envelope is invalid.');
        }

        return new self($data['kind'], $data['object_key'], $data['content_version'], $data['bytes'], $data['version_id']);
    }
}
