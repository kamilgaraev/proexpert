<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use Smalot\PdfParser\Parser;
use Throwable;

class OcrPreflightService
{
    private const OCR_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls'];

    public function __construct(private readonly PdfParserRuntime $pdfParserRuntime) {}

    public function validateForRecognition(EstimateGenerationDocument $document): void
    {
        $extension = $this->extension($document);

        if (!in_array($extension, [...self::OCR_EXTENSIONS, ...self::SPREADSHEET_EXTENSIONS], true)) {
            throw new OcrProviderException(
                'estimate_generation.ocr_unsupported_file',
                providerCode: 'unsupported_file_type',
                context: ['extension' => $extension],
            );
        }

        $maxBytes = $this->isSpreadsheet($document)
            ? (int) config('estimate-generation.ocr.max_spreadsheet_file_bytes', 50 * 1024 * 1024)
            : (int) config('estimate-generation.ocr.max_sync_file_bytes', 10 * 1024 * 1024);

        if ((int) ($document->file_size_bytes ?? 0) > $maxBytes) {
            throw new OcrProviderException(
                'estimate_generation.ocr_file_too_large',
                providerCode: 'file_too_large',
                context: [
                    'file_size_bytes' => $document->file_size_bytes,
                    'max_file_size_bytes' => $maxBytes,
                ],
            );
        }
    }

    public function validatePdfPageCount(EstimateGenerationDocument $document, string $content): ?int
    {
        if (!$this->isPdf($document)) {
            return null;
        }

        $pageCount = $this->detectPdfPageCount($content);

        if ($pageCount === null) {
            return null;
        }

        $maxPages = max(1, (int) config('estimate-generation.ocr.max_pdf_pages', 200));

        if ($pageCount > $maxPages) {
            throw new OcrProviderException(
                'estimate_generation.ocr_pdf_too_many_pages',
                providerCode: 'pdf_page_limit_exceeded',
                context: [
                    'page_count' => $pageCount,
                    'max_pdf_pages' => $maxPages,
                ],
            );
        }

        return $pageCount;
    }

    public function isSpreadsheet(EstimateGenerationDocument $document): bool
    {
        return in_array($this->extension($document), self::SPREADSHEET_EXTENSIONS, true);
    }

    public function isPdf(EstimateGenerationDocument $document): bool
    {
        return $this->extension($document) === 'pdf'
            || str_contains(strtolower((string) $document->mime_type), 'pdf');
    }

    public function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) ($document->meta['original_extension'] ?? pathinfo($document->filename, PATHINFO_EXTENSION)));
    }

    private function detectPdfPageCount(string $content): ?int
    {
        try {
            $pageCount = $this->pdfParserRuntime->withRaisedMemoryLimit(
                static fn (): int => count((new Parser())->parseContent($content)->getPages())
            );

            if ($pageCount > 0) {
                return $pageCount;
            }
        } catch (Throwable) {
        }

        $matches = [];
        $count = preg_match_all('/\/Type\s*\/Page\b/', $content, $matches);

        if ($count === false || $count === 0) {
            return null;
        }

        return $count;
    }
}
