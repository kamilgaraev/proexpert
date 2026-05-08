<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs;

final readonly class FgiscsDownloadDTO
{
    public function __construct(
        public string $content,
        public ?string $contentType,
        public ?string $fileName,
    ) {
    }
}
