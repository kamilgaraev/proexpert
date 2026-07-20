<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsBuildingResourcePriceDTO;
use OpenSpout\Reader\XLSX\Options as OpenSpoutXlsxOptions;
use OpenSpout\Reader\XLSX\Reader as OpenSpoutXlsxReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FgiscsBuildingResourcePriceSpreadsheetParser
{
    private const CHUNK_SIZE = 1000;

    private const RESOURCE_CODE_KIND_MATERIAL = 'material';

    private const RESOURCE_CODE_KIND_MACHINE = 'machine';

    private const RESOURCE_CODE_KIND_FREIGHT = 'freight';

    private const RESOURCE_CODE_KIND_UNKNOWN = 'unknown';

    public const HEADER_SCAN_ROWS = 100;

    public function parse(string $filePath): iterable
    {
        if (mb_strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) === 'xlsx') {
            yield from $this->parseXlsx($filePath);

            return;
        }

        $reader = IOFactory::createReader(IOFactory::identify($filePath));
        $reader->setReadDataOnly(true);

        $worksheetInfo = $reader->listWorksheetInfo($filePath);

        foreach ($worksheetInfo as $sheetIndex => $sheetInfo) {
            $totalRows = (int) ($sheetInfo['totalRows'] ?? 0);

            if ($totalRows < 1) {
                continue;
            }

            $filter = new FgiscsBuildingResourcePriceChunkReadFilter;
            $reader->setReadFilter($filter);
            $reader->setLoadSheetsOnly([(string) $sheetInfo['worksheetName']]);

            for ($startRow = 1; $startRow <= $totalRows; $startRow += self::CHUNK_SIZE) {
                $filter->setRows($startRow, self::CHUNK_SIZE);
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getSheet(0);

                foreach ($this->readWorksheetChunk($worksheet, $sheetIndex, $startRow) as $price) {
                    yield $price;
                }

                $spreadsheet->disconnectWorksheets();
                unset($spreadsheet);
            }
        }
    }

    private function parseXlsx(string $filePath): iterable
    {
        $options = new OpenSpoutXlsxOptions;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new OpenSpoutXlsxReader($options);
        $reader->open($filePath);

        try {
            foreach ($reader->getSheetIterator() as $sheetNumber => $sheet) {
                $sheetIndex = $sheetNumber - 1;
                $layout = null;
                $buffer = [];
                $materialSectionStarted = false;
                $materialSectionCompleted = false;

                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    $values = $row->toArray();

                    if ($layout === null && $rowNumber <= self::HEADER_SCAN_ROWS) {
                        $buffer[$rowNumber] = $values;
                        $layout = $this->detectLayoutFromRow($values, $rowNumber);

                        if ($layout === null) {
                            continue;
                        }

                        foreach ($buffer as $bufferedRowNumber => $bufferedValues) {
                            if ($bufferedRowNumber <= $layout['header_row']) {
                                continue;
                            }

                            if ($this->materialSectionBoundaryReached($bufferedValues, $layout, $materialSectionStarted)) {
                                $materialSectionCompleted = true;

                                break;
                            }

                            $price = $this->mapMappedValues(
                                $bufferedValues,
                                $sheetIndex,
                                $bufferedRowNumber,
                                $layout,
                            );

                            if ($price !== null) {
                                yield $price;
                            }
                        }

                        $buffer = [];

                        if ($materialSectionCompleted) {
                            break;
                        }

                        continue;
                    }

                    if ($layout !== null) {
                        if ($this->materialSectionBoundaryReached($values, $layout, $materialSectionStarted)) {
                            break;
                        }

                        $price = $this->mapMappedValues($values, $sheetIndex, $rowNumber, $layout);
                    } else {
                        foreach ($buffer as $bufferedRowNumber => $bufferedValues) {
                            if ($this->materialSectionBoundaryReached($bufferedValues, null, $materialSectionStarted)) {
                                $materialSectionCompleted = true;

                                break;
                            }

                            $price = $this->mapLegacySplitValues($bufferedValues, $sheetIndex, $bufferedRowNumber);

                            if ($price !== null) {
                                yield $price;
                            }
                        }

                        $buffer = [];

                        if ($materialSectionCompleted
                            || $this->materialSectionBoundaryReached($values, null, $materialSectionStarted)) {
                            break;
                        }

                        $price = $this->mapLegacySplitValues($values, $sheetIndex, $rowNumber);
                    }

                    if ($price !== null) {
                        yield $price;
                    }
                }

                if ($layout === null) {
                    foreach ($buffer as $rowNumber => $values) {
                        if ($this->materialSectionBoundaryReached($values, null, $materialSectionStarted)) {
                            break;
                        }

                        $price = $this->mapLegacySplitValues($values, $sheetIndex, $rowNumber);

                        if ($price !== null) {
                            yield $price;
                        }
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    private function readWorksheetChunk(Worksheet $worksheet, int $sheetIndex, int $startRow): iterable
    {
        $highestColumn = $worksheet->getHighestColumn();
        $highestRow = $worksheet->getHighestRow();
        $layout = $this->detectLayout($worksheet, $highestColumn);

        if ($layout !== null) {
            yield from $this->readMappedRows(
                $worksheet,
                $highestColumn,
                $highestRow,
                $sheetIndex,
                max($layout['header_row'] + 1, $startRow),
                $layout,
            );

            return;
        }

        yield from $this->readSplitFormRows($worksheet, $highestColumn, $highestRow, $sheetIndex, $startRow);
    }

    /**
     * @param  array{format:'direct'|'split',header_row:int,columns:array<string,int>}  $layout
     */
    private function readMappedRows(
        Worksheet $worksheet,
        string $highestColumn,
        int $highestRow,
        int $sheetIndex,
        int $firstDataRow,
        array $layout,
    ): iterable {
        for ($row = $firstDataRow; $row <= $highestRow; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $price = $this->mapMappedValues($values, $sheetIndex, $row, $layout);

            if ($price !== null) {
                yield $price;
            }
        }
    }

    private function readSplitFormRows(Worksheet $worksheet, string $highestColumn, int $highestRow, int $sheetIndex, int $startRow): iterable
    {
        for ($row = $startRow; $row <= $highestRow; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $price = $this->mapLegacySplitValues($values, $sheetIndex, $row);

            if ($price !== null) {
                yield $price;
            }
        }
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array{format:'direct'|'split',header_row:int,columns:array<string,int>}  $layout
     */
    private function mapMappedValues(array $values, int $sheetIndex, int $row, array $layout): ?FgiscsBuildingResourcePriceDTO
    {
        $columns = $layout['columns'];
        $code = $this->textAt($values, $columns, 'resource_code');

        if (! $this->isMaterialCode($code)) {
            return null;
        }

        $name = $this->textAt($values, $columns, 'name');
        $unit = $this->textAt($values, $columns, 'unit');

        if ($layout['format'] === 'split') {
            $basePrice = $this->floatAt($values, $columns, 'base_price');
            $directPrice = $this->floatAt($values, $columns, 'direct_current_price');
            $groupIndex = $this->floatAt($values, $columns, 'group_index');
            $currentPrice = $directPrice ?? ($basePrice !== null && $groupIndex !== null
                ? round($basePrice * $groupIndex, 4)
                : null);

            if ($name === '' || $currentPrice === null || $currentPrice <= 0) {
                return null;
            }

            return new FgiscsBuildingResourcePriceDTO(
                code: $code,
                name: $name,
                unit: $unit !== '' ? $unit : null,
                currentPrice: $currentPrice,
                sourcePriceKind: $directPrice !== null ? 'regional_building_resource_direct' : 'regional_building_resource_index',
                rawData: [
                    'sheet_index' => $sheetIndex,
                    'row_number' => $row,
                    'release_price' => $this->floatAt($values, $columns, 'release_price'),
                    'base_price' => $basePrice,
                    'group_code' => $this->textAt($values, $columns, 'group_code'),
                    'group_name' => $this->textAt($values, $columns, 'group_name'),
                    'direct_current_price' => $directPrice,
                    'group_index' => $groupIndex,
                ],
            );
        }

        $currentPrice = $this->floatAt($values, $columns, 'estimated_price');

        if ($name === '' || $currentPrice === null || $currentPrice <= 0) {
            return null;
        }

        return new FgiscsBuildingResourcePriceDTO(
            code: $code,
            name: $name,
            unit: $unit !== '' ? $unit : null,
            currentPrice: $currentPrice,
            sourcePriceKind: 'regional_building_resource_export',
            rawData: [
                'sheet_index' => $sheetIndex,
                'row_number' => $row,
                'release_price' => $this->floatAt($values, $columns, 'release_price'),
                'estimated_price' => $currentPrice,
            ],
        );
    }

    /** @param array<int, mixed> $values */
    private function mapLegacySplitValues(array $values, int $sheetIndex, int $row): ?FgiscsBuildingResourcePriceDTO
    {
        $code = $this->clean((string) ($values[0] ?? ''));

        if (! $this->isMaterialCode($code)) {
            return null;
        }

        $name = $this->clean((string) ($values[1] ?? ''));
        $unit = $this->clean((string) ($values[2] ?? ''));
        $basePrice = $this->toFloat($values[4] ?? null);
        $directPrice = $this->toFloat($values[7] ?? null);
        $groupIndex = $this->toFloat($values[8] ?? null);
        $currentPrice = $directPrice ?? ($basePrice !== null && $groupIndex !== null ? round($basePrice * $groupIndex, 4) : null);

        if ($name === '' || $currentPrice === null || $currentPrice <= 0) {
            return null;
        }

        return new FgiscsBuildingResourcePriceDTO(
            code: $code,
            name: $name,
            unit: $unit !== '' ? $unit : null,
            currentPrice: $currentPrice,
            sourcePriceKind: $directPrice !== null ? 'regional_building_resource_direct' : 'regional_building_resource_index',
            rawData: [
                'sheet_index' => $sheetIndex,
                'row_number' => $row,
                'release_price' => $this->toFloat($values[3] ?? null),
                'base_price' => $basePrice,
                'group_code' => $this->clean((string) ($values[5] ?? '')),
                'group_name' => $this->clean((string) ($values[6] ?? '')),
                'direct_current_price' => $directPrice,
                'group_index' => $groupIndex,
            ],
        );
    }

    /**
     * @return array{format:'direct'|'split',header_row:int,columns:array<string,int>}|null
     */
    private function detectLayout(Worksheet $worksheet, string $highestColumn): ?array
    {
        $limit = min($worksheet->getHighestRow(), self::HEADER_SCAN_ROWS);

        for ($row = 1; $row <= $limit; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $layout = $this->detectLayoutFromRow($values, $row);

            if ($layout !== null) {
                return $layout;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array{format:'direct'|'split',header_row:int,columns:array<string,int>}|null
     */
    private function detectLayoutFromRow(array $values, int $row): ?array
    {
        $columns = [];

        foreach ($values as $column => $value) {
            $key = $this->normalizeHeader((string) $value);

            if ($key !== null && ! isset($columns[$key])) {
                $columns[$key] = $column;
            }
        }

        if (! $this->hasColumns($columns, ['resource_code', 'name', 'unit'])) {
            return null;
        }

        if ($this->hasColumns($columns, ['base_price', 'direct_current_price', 'group_code', 'group_index'])) {
            return ['format' => 'split', 'header_row' => $row, 'columns' => $columns];
        }

        if (isset($columns['estimated_price'])) {
            return ['format' => 'direct', 'header_row' => $row, 'columns' => $columns];
        }

        if (isset($columns['direct_current_price'])) {
            $columns['estimated_price'] = $columns['direct_current_price'];

            return ['format' => 'direct', 'header_row' => $row, 'columns' => $columns];
        }

        return null;
    }

    private function isMaterialCode(string $code): bool
    {
        return preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}-\d{4}$/', $code) === 1;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array{format:'direct'|'split',header_row:int,columns:array<string,int>}|null  $layout
     */
    private function materialSectionBoundaryReached(array $values, ?array $layout, bool &$materialSectionStarted): bool
    {
        $code = $layout !== null
            ? $this->textAt($values, $layout['columns'], 'resource_code')
            : $this->clean((string) ($values[0] ?? ''));
        $kind = $this->resourceCodeKind($code);

        if ($kind === self::RESOURCE_CODE_KIND_MATERIAL) {
            $materialSectionStarted = true;

            return false;
        }

        return $materialSectionStarted && in_array($kind, [
            self::RESOURCE_CODE_KIND_MACHINE,
            self::RESOURCE_CODE_KIND_FREIGHT,
        ], true);
    }

    private function resourceCodeKind(string $code): string
    {
        return match (true) {
            $this->isMaterialCode($code) => self::RESOURCE_CODE_KIND_MATERIAL,
            preg_match('/^\d{2}\.\d{2}\.\d{2}-\d{3,4}$/', $code) === 1 => self::RESOURCE_CODE_KIND_MACHINE,
            preg_match('/^\d{2}-\d{2}-\d-\d{2}-\d{4}$/', $code) === 1,
            preg_match('/^\d{6}-\d{2}-\d{4}-\d{4}$/', $code) === 1 => self::RESOURCE_CODE_KIND_FREIGHT,
            default => self::RESOURCE_CODE_KIND_UNKNOWN,
        };
    }

    private function normalizeHeader(string $value): ?string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'ё' => 'е',
            ' ' => '',
            "\n" => '',
            "\r" => '',
            '.' => '',
            '-' => '',
            '_' => '',
            '/' => '',
            ',' => '',
            '(' => '',
            ')' => '',
        ]);

        return match (true) {
            str_contains($value, 'кодгруппыоднородныхстроительныхресурсов') => 'group_code',
            str_contains($value, 'наименованиегруппыоднородныхстроительныхресурсов') => 'group_name',
            str_contains($value, 'кодстроительногоресурса'),
            str_contains($value, 'кодматериальногоресурса'),
            str_starts_with($value, 'кодгруппыресурса'),
            $value === 'кодресурса' => 'resource_code',
            $value === 'наименование',
            str_starts_with($value, 'наименованиересурса'),
            str_contains($value, 'наименованиестроительногоресурса'),
            str_contains($value, 'наименованиематериальногоресурса') => 'name',
            str_starts_with($value, 'единицаизмерения'),
            str_starts_with($value, 'едизм') => 'unit',
            str_contains($value, 'отпускнаяцена') => 'release_price',
            str_contains($value, 'сметнаяцена') && str_contains($value, 'базисномуровнецен') => 'base_price',
            str_contains($value, 'сметнаяцена') && str_contains($value, 'текущемуровнецен') => 'direct_current_price',
            str_contains($value, 'индексизменениясметнойстоимости') => 'group_index',
            str_contains($value, 'сметнаяцена') => 'estimated_price',
            default => null,
        };
    }

    /** @param array<string, int> $columns @param list<string> $required */
    private function hasColumns(array $columns, array $required): bool
    {
        return array_diff($required, array_keys($columns)) === [];
    }

    /** @param array<int, mixed> $values @param array<string, int> $columns */
    private function textAt(array $values, array $columns, string $key): string
    {
        $column = $columns[$key] ?? null;

        return $column !== null ? $this->clean((string) ($values[$column] ?? '')) : '';
    }

    /** @param array<int, mixed> $values @param array<string, int> $columns */
    private function floatAt(array $values, array $columns, string $key): ?float
    {
        $column = $columns[$key] ?? null;

        return $column !== null ? $this->toFloat($values[$column] ?? null) : null;
    }

    private function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

final class FgiscsBuildingResourcePriceChunkReadFilter implements IReadFilter
{
    private int $startRow = 1;

    private int $endRow = 1;

    public function setRows(int $startRow, int $chunkSize): void
    {
        $this->startRow = $startRow;
        $this->endRow = $startRow + $chunkSize - 1;
    }

    public function readCell($columnAddress, $row, $worksheetName = ''): bool
    {
        return $row <= FgiscsBuildingResourcePriceSpreadsheetParser::HEADER_SCAN_ROWS
            || ($row >= $this->startRow && $row <= $this->endRow);
    }
}
