<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Ocr;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Throwable;

class SpreadsheetDocumentExtractor
{
    public const PROVIDER = 'spreadsheet_parser';

    public const MODEL = 'spreadsheet_text_v1';

    public function extract(EstimateGenerationDocument $document, string $content): OcrRecognitionResult
    {
        $extension = strtolower((string) ($document->meta['original_extension'] ?? pathinfo($document->filename, PATHINFO_EXTENSION)));
        $tempPath = tempnam(sys_get_temp_dir(), 'estimate-generation-spreadsheet-');

        if ($tempPath === false) {
            throw new OcrProviderException(
                'estimate_generation.spreadsheet_parse_error',
                providerCode: 'spreadsheet_temp_file_error',
            );
        }

        $tempPathWithExtension = $tempPath . '.' . ($extension !== '' ? $extension : 'xlsx');

        if (!rename($tempPath, $tempPathWithExtension)) {
            if (is_file($tempPath)) {
                unlink($tempPath);
            }

            throw new OcrProviderException(
                'estimate_generation.spreadsheet_parse_error',
                providerCode: 'spreadsheet_temp_file_error',
            );
        }

        try {
            if (file_put_contents($tempPathWithExtension, $content) === false) {
                throw new OcrProviderException(
                    'estimate_generation.spreadsheet_parse_error',
                    providerCode: 'spreadsheet_temp_file_error',
                );
            }

            $reader = IOFactory::createReaderForFile($tempPathWithExtension);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($tempPathWithExtension);

            try {
                $pages = $this->pagesFromSpreadsheet($spreadsheet);
            } finally {
                $spreadsheet->disconnectWorksheets();
            }

            return new OcrRecognitionResult(
                provider: self::PROVIDER,
                model: self::MODEL,
                pages: $pages,
                rawPayload: [
                    'sheets_count' => count($pages),
                    'source' => 'spreadsheet',
                ],
                metadata: [
                    'mime_type' => $document->mime_type,
                    'extension' => $extension,
                ],
            );
        } catch (OcrProviderException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new OcrProviderException(
                'estimate_generation.spreadsheet_parse_error',
                providerCode: 'spreadsheet_parse_error',
                previous: $exception,
            );
        } finally {
            if (is_file($tempPathWithExtension)) {
                unlink($tempPathWithExtension);
            }
        }
    }

    /**
     * @return array<int, OcrPageResult>
     */
    private function pagesFromSpreadsheet(Spreadsheet $spreadsheet): array
    {
        $pages = [];
        $maxRows = (int) config('estimate-generation.ocr.max_spreadsheet_rows', 2000);
        $maxColumns = (int) config('estimate-generation.ocr.max_spreadsheet_columns', 80);
        $languages = array_values((array) config('estimate-generation.ocr.languages', ['ru', 'en']));

        foreach ($spreadsheet->getAllSheets() as $index => $worksheet) {
            $highestRow = min($worksheet->getHighestDataRow(), $maxRows);
            $highestColumnIndex = min(
                Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
                $maxColumns,
            );
            $highestColumn = Coordinate::stringFromColumnIndex($highestColumnIndex);
            $lines = ['Sheet: ' . $worksheet->getTitle()];

            foreach ($worksheet->getRowIterator(1, $highestRow) as $row) {
                $values = [];
                $cellIterator = $row->getCellIterator('A', $highestColumn);
                $cellIterator->setIterateOnlyExistingCells(true);

                foreach ($cellIterator as $cell) {
                    $value = trim($this->cellValue($cell));

                    if ($value !== '') {
                        $values[] = $value;
                    }
                }

                if ($values !== []) {
                    $lines[] = implode(' ', $values);
                }
            }

            $text = trim(implode("\n", $lines));

            $pages[] = new OcrPageResult(
                pageNumber: $index + 1,
                text: $text,
                blocks: [],
                confidence: $text !== '' ? 1.0 : 0.0,
                languageCodes: $languages,
                rawPayload: [
                    'sheet_title' => $worksheet->getTitle(),
                    'rows_scanned' => $highestRow,
                    'columns_scanned' => $highestColumnIndex,
                ],
            );
        }

        return $pages !== [] ? $pages : [
            new OcrPageResult(pageNumber: 1, text: '', confidence: 0.0, languageCodes: $languages),
        ];
    }

    private function cellValue(Cell $cell): string
    {
        try {
            $value = $cell->getFormattedValue();
        } catch (Throwable) {
            $value = $cell->getCalculatedValue();
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }
}
