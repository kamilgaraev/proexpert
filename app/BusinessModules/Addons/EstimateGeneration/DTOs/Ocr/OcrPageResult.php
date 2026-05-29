<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr;

final readonly class OcrPageResult
{
    /**
     * @param array<int, array<string, mixed>> $blocks
     * @param array<int, string> $languageCodes
     * @param array<string, mixed> $rawPayload
     */
    public function __construct(
        public int $pageNumber,
        public string $text,
        public array $blocks = [],
        public ?int $width = null,
        public ?int $height = null,
        public ?int $rotation = null,
        public ?float $confidence = null,
        public array $languageCodes = [],
        public array $rawPayload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRawPayload = false): array
    {
        $payload = [
            'page_number' => $this->pageNumber,
            'text' => $this->text,
            'blocks' => $this->blocks,
            'width' => $this->width,
            'height' => $this->height,
            'rotation' => $this->rotation,
            'confidence' => $this->confidence,
            'language_codes' => $this->languageCodes,
        ];

        if ($includeRawPayload) {
            $payload['raw_payload'] = $this->rawPayload;
        }

        return $payload;
    }
}
