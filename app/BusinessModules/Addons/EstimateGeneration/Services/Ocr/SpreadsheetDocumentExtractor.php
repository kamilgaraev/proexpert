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

    public function __construct(private XlsxContainerInspector $containerInspector = new XlsxContainerInspector) {}

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
            $mime = strtolower((string) $document->mime_type);
            $xlsxExpected = $extension === 'xlsx'
                || str_contains($mime, 'openxmlformats-officedocument.spreadsheetml')
                || $this->isXlsxPackage($path);
            $containerMetadata = $xlsxExpected
                ? $this->containerInspector->inspect($path)
                : new XlsxContainerMetadata([]);
            $readerType = IOFactory::identify($path);
            if ($readerType === IOFactory::READER_XLSX && ! $xlsxExpected) {
                $containerMetadata = $this->containerInspector->inspect($path);
            }
            $reader = IOFactory::createReader($readerType);
            $maxRows = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_rows', 2000));
            $maxColumns = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_columns', 80));
            $maxSheets = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_sheets', 32));
            $maxCells = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_cells', 20_000));
            $worksheetInfo = $reader->listWorksheetInfo($path);
            $loadedSheetCount = min(count($worksheetInfo), $maxSheets, $maxCells);
            $selectedInfo = array_slice($worksheetInfo, 0, $loadedSheetCount);
            $sheetNames = array_values(array_filter(array_map(
                static fn (array $sheet): mixed => $sheet['worksheetName'] ?? null,
                $selectedInfo,
            ), 'is_string'));
            $readBounds = $this->readBounds($selectedInfo, $maxRows, $maxColumns, $maxCells);
            $reader->setReadDataOnly(true);
            $reader->setReadFilter(new BoundedSpreadsheetReadFilter($readBounds));
            $reader->setLoadSheetsOnly($sheetNames);
            $spreadsheet = $reader->load($path);

            try {
                $pages = $this->pagesFromSpreadsheet(
                    $spreadsheet,
                    $selectedInfo,
                    count($worksheetInfo) > $loadedSheetCount,
                    $readBounds,
                    $containerMetadata,
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
    private function pagesFromSpreadsheet(
        Spreadsheet $spreadsheet,
        array $worksheetInfo,
        bool $sheetsTruncated,
        array $readBounds,
        XlsxContainerMetadata $containerMetadata,
    ): array {
        $pages = [];
        $maxRenderCells = max(1, (int) config('estimate-generation.ocr.max_spreadsheet_render_cells', 400));
        $languages = array_values((array) config('estimate-generation.ocr.languages', ['ru', 'en']));

        foreach ($spreadsheet->getAllSheets() as $index => $worksheet) {
            $info = is_array($worksheetInfo[$index] ?? null) ? $worksheetInfo[$index] : [];
            $sourceRows = max(0, (int) ($info['totalRows'] ?? $worksheet->getHighestDataRow()));
            $sourceColumns = max(0, (int) ($info['totalColumns'] ?? Coordinate::columnIndexFromString($worksheet->getHighestDataColumn())));
            $bounds = $readBounds[$worksheet->getTitle()] ?? ['rows' => 0, 'columns' => 0, 'cells' => 0];
            $loadedCells = count($worksheet->getCellCollection()->getCoordinates());
            $limitations = [];
            if ($sheetsTruncated) {
                $limitations[] = 'xlsx_sheets_truncated';
            }
            if ($sourceRows > $bounds['rows']) {
                $limitations[] = 'xlsx_rows_truncated';
            }
            if ($sourceColumns > $bounds['columns']) {
                $limitations[] = 'xlsx_columns_truncated';
            }
            if ($sourceRows * $sourceColumns > $bounds['cells']) {
                $limitations[] = 'xlsx_cells_truncated';
            }
            $highestRow = min($worksheet->getHighestDataRow(), $bounds['rows']);
            $highestColumnIndex = min(
                Coordinate::columnIndexFromString($worksheet->getHighestDataColumn()),
                $bounds['columns'],
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
            [$merges, $mergesTruncated] = $this->boundedMerges(
                $containerMetadata->mergesBySheet[$worksheet->getTitle()] ?? [],
                $bounds,
            );
            if ($mergesTruncated
                || in_array('xlsx_merges_truncated', $containerMetadata->mergeLimitationsBySheet[$worksheet->getTitle()] ?? [], true)) {
                $limitations[] = 'xlsx_merges_truncated';
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
                    $merges,
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
                        'merges' => $merges,
                        'native_reference_registry' => $nativeReferences,
                        'loaded_cells' => $loadedCells,
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

    /** @return array<string, array{rows: int, columns: int, cells: int}> */
    private function readBounds(array $worksheetInfo, int $maxRows, int $maxColumns, int $maxCells): array
    {
        $bounds = [];
        $remainingCells = $maxCells;
        $remainingSheets = count($worksheetInfo);
        foreach ($worksheetInfo as $sheet) {
            $quota = max(1, intdiv($remainingCells, max(1, $remainingSheets)));
            $sourceRows = max(1, (int) ($sheet['totalRows'] ?? 1));
            $sourceColumns = max(1, (int) ($sheet['totalColumns'] ?? 1));
            $columns = min($maxColumns, $sourceColumns, $quota);
            $rows = min($maxRows, $sourceRows, max(1, (int) ceil($quota / $columns)));
            $used = min($quota, $rows * $columns);
            $name = $sheet['worksheetName'] ?? null;
            if (is_string($name)) {
                $bounds[$name] = ['rows' => $rows, 'columns' => $columns, 'cells' => $used];
            }
            $remainingCells -= $used;
            $remainingSheets--;
        }

        return $bounds;
    }

    /** @param list<string> $ranges @param array{rows: int, columns: int, cells: int} $bounds @return array{list<string>, bool} */
    private function boundedMerges(array $ranges, array $bounds): array
    {
        $included = [];
        $truncated = false;
        foreach ($ranges as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $endOrdinal = (($end[1] - 1) * $bounds['columns']) + $end[0];
            if ($start[0] < 1 || $start[1] < 1 || $end[0] > $bounds['columns'] || $end[1] > $bounds['rows']
                || $endOrdinal > $bounds['cells']) {
                $truncated = true;

                continue;
            }
            $included[] = $range;
        }

        return [$included, $truncated];
    }

    private function isXlsxPackage(string $path): bool
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            if (fread($handle, 4) !== "PK\x03\x04") {
                return false;
            }
        } finally {
            fclose($handle);
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }
        try {
            return $zip->locateName('[Content_Types].xml', ZipArchive::FL_NOCASE) !== false;
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
