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
use ZipArchive;

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

        $tempPathWithExtension = $tempPath.'.'.($extension !== '' ? $extension : 'xlsx');

        if (! rename($tempPath, $tempPathWithExtension)) {
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

            return $this->extractFile($document, $tempPathWithExtension);
        } finally {
            if (is_file($tempPathWithExtension)) {
                unlink($tempPathWithExtension);
            }
        }
    }

    public function extractFile(EstimateGenerationDocument $document, string $path): OcrRecognitionResult
    {
        $extension = strtolower((string) ($document->meta['original_extension'] ?? pathinfo($document->filename, PATHINFO_EXTENSION)));

        try {
            if ($extension === 'xlsx') {
                $this->assertSafeXlsxContainer($path);
            }
            $reader = IOFactory::createReaderForFile($path);
            $maxRows = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_rows', 2000));
            $maxColumns = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_columns', 80));
            $maxSheets = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_sheets', 32));
            $worksheetInfo = $reader->listWorksheetInfo($path);
            $sheetNames = array_values(array_filter(array_map(
                static fn (array $sheet): mixed => $sheet['worksheetName'] ?? null,
                array_slice($worksheetInfo, 0, $maxSheets),
            ), 'is_string'));
            $reader->setReadFilter(new BoundedSpreadsheetReadFilter($maxRows, $maxColumns));
            if (count($worksheetInfo) > $maxSheets) {
                $reader->setLoadSheetsOnly($sheetNames);
            }
            $spreadsheet = $reader->load($path);

            try {
                $pages = $this->pagesFromSpreadsheet(
                    $spreadsheet,
                    array_slice($worksheetInfo, 0, $maxSheets),
                    count($worksheetInfo) > $maxSheets,
                );
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
        }
    }

    /**
     * @return array<int, OcrPageResult>
     */
    private function pagesFromSpreadsheet(Spreadsheet $spreadsheet, array $worksheetInfo, bool $sheetsTruncated): array
    {
        $pages = [];
        $maxRows = (int) config('estimate-generation.ocr.max_spreadsheet_rows', 2000);
        $maxColumns = (int) config('estimate-generation.ocr.max_spreadsheet_columns', 80);
        $maxCells = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_cells', 20_000));
        $maxRenderCells = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_render_cells', 400));
        $languages = array_values((array) config('estimate-generation.ocr.languages', ['ru', 'en']));

        foreach ($spreadsheet->getAllSheets() as $index => $worksheet) {
            $info = is_array($worksheetInfo[$index] ?? null) ? $worksheetInfo[$index] : [];
            $sourceRows = max(0, (int) ($info['totalRows'] ?? $worksheet->getHighestDataRow()));
            $sourceColumns = max(0, (int) ($info['totalColumns'] ?? Coordinate::columnIndexFromString($worksheet->getHighestDataColumn())));
            $limitations = [];
            if ($sheetsTruncated) {
                $limitations[] = 'xlsx_sheets_truncated';
            }
            if ($sourceRows > $maxRows) {
                $limitations[] = 'xlsx_rows_truncated';
            }
            if ($sourceColumns > $maxColumns) {
                $limitations[] = 'xlsx_columns_truncated';
            }
            if ($sourceRows * $sourceColumns > $maxCells) {
                $limitations[] = 'xlsx_cells_truncated';
            }
            $highestRow = min($worksheet->getHighestDataRow(), $maxRows);
            $highestColumnIndex = min(
                Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
                $maxColumns,
            );
            $highestColumn = Coordinate::stringFromColumnIndex($highestColumnIndex);
            $lines = ['Sheet: '.$worksheet->getTitle()];
            $cells = [];
            $headings = [];
            $firstPopulatedRow = null;

            foreach ($worksheet->getRowIterator(1, $highestRow) as $row) {
                $values = [];
                $cellIterator = $row->getCellIterator('A', $highestColumn);
                $cellIterator->setIterateOnlyExistingCells(true);

                foreach ($cellIterator as $cell) {
                    if (count($cells) >= $maxCells) {
                        if (! in_array('xlsx_cells_truncated', $limitations, true)) {
                            $limitations[] = 'xlsx_cells_truncated';
                        }
                        break 2;
                    }
                    $value = trim($this->cellValue($cell));

                    if ($value !== '') {
                        $values[] = $value;
                        $cells[] = [
                            'address' => $cell->getCoordinate(),
                            'value' => $value,
                            'formula' => $this->formula($cell),
                        ];
                        if ($firstPopulatedRow === null) {
                            $headings[] = $cell->getCoordinate();
                        }
                    }
                }

                if ($values !== []) {
                    $lines[] = implode(' ', $values);
                    $firstPopulatedRow ??= $row->getRowIndex();
                }
            }

            $text = trim(implode("\n", $lines));
            if (count($cells) > $maxRenderCells) {
                $limitations[] = 'xlsx_render_truncated';
            }
            $limitations = array_values(array_unique($limitations));
            $nativeReferences = array_values(array_unique(array_merge(
                array_map(
                    static fn (array $cell): string => sprintf(
                        'xlsx:sheet:%s!%s',
                        $worksheet->getTitle(),
                        (string) $cell['address'],
                    ),
                    $cells,
                ),
                array_map(
                    static fn (string $range): string => sprintf('xlsx:sheet:%s!%s', $worksheet->getTitle(), $range),
                    array_values($worksheet->getMergeCells()),
                ),
            )));

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
                    'native_structure' => [
                        'status' => $limitations === [] ? 'available' : 'partial',
                        'limitations' => $limitations,
                        'sheet' => $worksheet->getTitle(),
                        'headings' => $headings,
                        'cells' => $cells,
                        'formulas' => array_values(array_filter(
                            $cells,
                            static fn (array $cell): bool => $cell['formula'] !== null,
                        )),
                        'merges' => array_values($worksheet->getMergeCells()),
                        'native_reference_registry' => $nativeReferences,
                        'rows' => $highestRow,
                        'columns' => $highestColumnIndex,
                    ],
                ],
            );
        }

        return $pages !== [] ? $pages : [
            new OcrPageResult(pageNumber: 1, text: '', confidence: 0.0, languageCodes: $languages),
        ];
    }

    private function cellValue(Cell $cell): string
    {
        $value = $cell->getValue();

        if (is_scalar($value)) {
            return (string) $value;
        }

        return '';
    }

    private function assertSafeXlsxContainer(string $path): void
    {
        $maxEntries = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_zip_entries', 2048));
        $maxUncompressed = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_uncompressed_bytes', 20_000_000));
        $maxRatio = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_compression_ratio', 100));
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new OcrProviderException(
                'estimate_generation.spreadsheet_parse_error',
                providerCode: 'spreadsheet_container_invalid',
            );
        }

        try {
            if ($zip->numFiles < 1 || $zip->numFiles > $maxEntries) {
                throw new OcrProviderException(
                    'estimate_generation.spreadsheet_parse_error',
                    providerCode: 'spreadsheet_container_limit_exceeded',
                );
            }
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
                if (! is_array($stat)) {
                    throw new OcrProviderException(
                        'estimate_generation.spreadsheet_parse_error',
                        providerCode: 'spreadsheet_container_invalid',
                    );
                }
                $name = (string) ($stat['name'] ?? '');
                $normalizedName = str_replace('\\', '/', $name);
                $size = max(0, (int) ($stat['size'] ?? 0));
                $compressed = max(0, (int) ($stat['comp_size'] ?? 0));
                $total += $size;
                $ratio = $compressed === 0 ? ($size === 0 ? 1.0 : INF) : $size / $compressed;
                if ($name === '' || str_contains($name, "\0")
                    || in_array('..', explode('/', $normalizedName), true)
                    || str_starts_with($normalizedName, '/')
                    || preg_match('/^[A-Za-z]:\//', $normalizedName) === 1
                    || $total > $maxUncompressed || $ratio > $maxRatio) {
                    throw new OcrProviderException(
                        'estimate_generation.spreadsheet_parse_error',
                        providerCode: 'spreadsheet_container_limit_exceeded',
                    );
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function formula(Cell $cell): ?string
    {
        $value = $cell->getValue();

        return is_string($value) && str_starts_with($value, '=') ? $value : null;
    }
}
