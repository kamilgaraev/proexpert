<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr;

final readonly class OcrRecognitionResult
{
    /**
     * @param array<int, OcrPageResult> $pages
     * @param array<string, mixed> $rawPayload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $provider,
        public string $model,
        public array $pages,
        public array $rawPayload = [],
        public array $metadata = [],
    ) {}

    public function text(): string
    {
        return trim(implode("\n", array_map(
            static fn (OcrPageResult $page): string => $page->text,
            $this->pages
        )));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(bool $includeRawPayload = false): array
    {
        $payload = [
            'provider' => $this->provider,
            'model' => $this->model,
            'text' => $this->text(),
            'pages' => array_map(
                static fn (OcrPageResult $page): array => $page->toArray(),
                $this->pages
            ),
            'metadata' => $this->metadata,
        ];

        if ($includeRawPayload) {
            $payload['pages'] = array_map(
                static fn (OcrPageResult $page): array => $page->toArray(includeRawPayload: true),
                $this->pages
            );
        }

        if ($includeRawPayload) {
            $payload['raw_payload'] = $this->rawPayload;
        }

        return $payload;
    }
}
