<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents;

use InvalidArgumentException;

final readonly class StoredDocumentArtifact
{
    public function __construct(
        public string $path,
        public int $bytes,
        public string $sha256,
        public string $versionId,
        public string $contentType,
    ) {
        if ($bytes < 1 || preg_match('/\Asha256:[0-9a-f]{64}\z/', $sha256) !== 1
            || preg_match('/\A[\x21-\x7e]{1,1024}\z/D', $versionId) !== 1) {
            throw new InvalidArgumentException('Document artifact locator is invalid.');
        }
    }

    /** @return array{artifact_path:string,artifact_bytes:int,artifact_sha256:string,artifact_version_id:string,content_type:string} */
    public function locator(): array
    {
        return [
            'artifact_path' => $this->path,
            'artifact_bytes' => $this->bytes,
            'artifact_sha256' => $this->sha256,
            'artifact_version_id' => $this->versionId,
            'content_type' => $this->contentType,
        ];
    }
}
