<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Storage;

final readonly class VersionedS3ObjectContent
{
    public function __construct(
        public string $body,
        public int $bytes,
        public string $sha256,
        public string $versionId,
        public string $contentType,
    ) {}
}
