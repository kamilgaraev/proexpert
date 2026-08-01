<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Documents\Spreadsheet;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use InvalidArgumentException;

final class SpreadsheetDocumentAdapter
{
    /**
     * @return array{schema_version: int, source_kind: string, text: string, native_structure: array<string, mixed>}
     */
    public function extract(OcrPageResult $page): array
    {
        $nativeStructure = $page->rawPayload['native_structure'] ?? null;

        if (! is_array($nativeStructure) || ($nativeStructure['status'] ?? null) !== 'available') {
            throw new InvalidArgumentException('spreadsheet_native_structure_unavailable');
        }

        return [
            'schema_version' => 1,
            'source_kind' => 'spreadsheet',
            'text' => $page->text,
            'native_structure' => $nativeStructure,
        ];
    }
}
