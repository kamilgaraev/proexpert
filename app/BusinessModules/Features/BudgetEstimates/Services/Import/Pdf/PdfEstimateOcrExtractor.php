<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class PdfEstimateOcrExtractor
{
    public function __construct(
        private OcrClientInterface $ocrClient,
    ) {}

    /**
     * @return array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>}
     */
    public function extract(string $filePath): array
    {
        if (! (bool) config('estimate-generation.ocr.enabled', true)) {
            return $this->failed('ocr_disabled', trans_message('estimate.import_pdf_ocr_disabled'));
        }

        $content = is_readable($filePath) ? file_get_contents($filePath) : false;
        if ($content === false) {
            return $this->failed('ocr_failed', trans_message('estimate.import_pdf_ocr_failed'));
        }

        $pageCount = $this->detectPageCount($content);

        try {
            $result = $this->ocrClient->recognize(new OcrDocumentInput(
                content: $content,
                mimeType: 'application/pdf',
                filename: basename($filePath),
                pageCount: $pageCount,
            ));
        } catch (OcrConfigurationException | OcrProviderException | Throwable $exception) {
            Log::warning('[BudgetEstimates Import] PDF OCR recognition failed', [
                'file' => basename($filePath),
                'page_count' => $pageCount,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return $this->failed('ocr_failed', trans_message('estimate.import_pdf_ocr_failed'), [
                'ocr_exception' => $exception::class,
                'pdf_page_count' => $pageCount,
            ]);
        }

        $text = $result->text();

        return [
            'text' => $text,
            'method' => 'ocr_' . $result->provider,
            'warnings' => $text === '' ? [trans_message('estimate.import_pdf_ocr_empty')] : [],
            'metadata' => [
                'ocr_provider' => $result->provider,
                'ocr_model' => $result->model,
                'ocr_page_count' => count($result->pages),
                'ocr_async' => (bool) ($result->metadata['async'] ?? false),
                'pdf_page_count' => $pageCount,
            ],
        ];
    }

    private function detectPageCount(string $content): ?int
    {
        $matched = preg_match_all('/\/Type\s*\/Page\b/', $content);

        return is_int($matched) && $matched > 0 ? $matched : null;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array{text: string, method: string, warnings: array<int, string>, metadata: array<string, mixed>}
     */
    private function failed(string $method, string $warning, array $metadata = []): array
    {
        return [
            'text' => '',
            'method' => $method,
            'warnings' => [$warning],
            'metadata' => $metadata,
        ];
    }
}
