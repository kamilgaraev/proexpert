<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;

final readonly class OcrDocumentInput
{
    public function __construct(
        public string $content,
        public string $mimeType,
        public ?string $filename = null,
        public ?int $pageCount = null,
        public ?AiOperationContext $operationContext = null,
    ) {}
}
