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
        public string $contentType,
    ) {
        if ($bytes < 1 || preg_match('/\Asha256:[0-9a-f]{64}\z/', $sha256) !== 1) {
            throw new InvalidArgumentException('Document artifact locator is invalid.');
        }
    }

    /** @return array{artifact_path:string,artifact_bytes:int,artifact_sha256:string,content_type:string} */
    public function locator(): array
    {
        return [
            'artifact_path' => $this->path,
            'artifact_bytes' => $this->bytes,
            'artifact_sha256' => $this->sha256,
            'content_type' => $this->contentType,
        ];
    }
}
