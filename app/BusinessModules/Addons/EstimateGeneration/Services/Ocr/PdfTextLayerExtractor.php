<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use Illuminate\Support\Facades\Log;
use App\Services\Documents\BoundedPdfParser;
use Throwable;

class PdfTextLayerExtractor
{
    public const PROVIDER = 'pdf_text_layer';

    public const MODEL = 'embedded_text';

    public function __construct(private readonly BoundedPdfParser $pdfParser) {}

    public function extract(string $content, ?string $filename = null): ?OcrRecognitionResult
    {
        try {
            $pages = $this->pdfParser->inspectContent($content)['pages'];
        } catch (Throwable $exception) {
            Log::info('[EstimateGeneration OCR] PDF text layer extraction skipped', [
                'failure_code' => 'pdf_text_layer_unreadable',
                'failure_fingerprint' => hash('sha256', $exception::class.'|'.(string) $exception->getCode()),
            ]);

            return null;
        }

        return $this->result($pages, $filename);
    }

    public function extractFile(string $path, ?string $filename = null): ?OcrRecognitionResult
    {
        try {
            $pages = $this->pdfParser->inspectFile($path)['pages'];
        } catch (Throwable $exception) {
            Log::info('[EstimateGeneration OCR] PDF text layer extraction skipped', [
                'failure_code' => 'pdf_text_layer_unreadable',
                'failure_fingerprint' => hash('sha256', $exception::class.'|'.(string) $exception->getCode()),
            ]);

            return null;
        }

        return $this->result($pages, $filename);
    }

    /**
     * @param  array<int, mixed>  $pages
     */
    private function result(array $pages, ?string $filename): ?OcrRecognitionResult
    {

        $pageResults = [];

        foreach ($pages as $index => $page) {
            $text = trim($page);

            $pageResults[] = new OcrPageResult(
                pageNumber: $index + 1,
                text: $text,
                confidence: $text !== '' ? 1.0 : null,
                languageCodes: [],
                rawPayload: [
                    'source' => self::PROVIDER,
                    'capability' => [
                        'text_layer' => 'available',
                    ],
                ],
            );
        }

        if (! $this->hasUsefulText($pageResults)) {
            return null;
        }

        return new OcrRecognitionResult(
            provider: self::PROVIDER,
            model: self::MODEL,
            pages: $pageResults,
            rawPayload: [
                'page_count' => count($pageResults),
                'capability' => [
                    'text_layer' => 'available',
                ],
            ],
            metadata: [
                'mime_type' => 'application/pdf',
                'filename' => $filename,
                'source' => self::PROVIDER,
            ],
        );
    }

    /**
     * @param  array<int, OcrPageResult>  $pages
     */
    private function hasUsefulText(array $pages): bool
    {
        $text = trim(implode("\n", array_map(
            static fn (OcrPageResult $page): string => $page->text,
            $pages
        )));

        return mb_strlen($text) >= max(1, (int) config('estimate-generation.ocr.pdf_text_layer_min_chars', 20));
    }
}
