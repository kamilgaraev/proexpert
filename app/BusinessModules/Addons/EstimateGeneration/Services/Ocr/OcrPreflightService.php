<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;

class OcrPreflightService
{
    private const OCR_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png'];

    private const SPREADSHEET_EXTENSIONS = ['xlsx', 'xls'];

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

    public function isSpreadsheet(EstimateGenerationDocument $document): bool
    {
        return in_array($this->extension($document), self::SPREADSHEET_EXTENSIONS, true);
    }

    public function extension(EstimateGenerationDocument $document): string
    {
        return strtolower((string) ($document->meta['original_extension'] ?? pathinfo($document->filename, PATHINFO_EXTENSION)));
    }
}
