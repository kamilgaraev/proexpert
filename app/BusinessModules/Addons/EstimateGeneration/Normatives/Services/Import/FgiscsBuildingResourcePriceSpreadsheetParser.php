<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\FgiscsBuildingResourcePriceDTO;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FgiscsBuildingResourcePriceSpreadsheetParser
{
    private const CHUNK_SIZE = 1000;

    public function parse(string $filePath): iterable
    {
        $reader = IOFactory::createReader(IOFactory::identify($filePath));
        $reader->setReadDataOnly(true);

        $worksheetInfo = $reader->listWorksheetInfo($filePath);

        foreach ($worksheetInfo as $sheetIndex => $sheetInfo) {
            $totalRows = (int) ($sheetInfo['totalRows'] ?? 0);

            if ($totalRows < 1) {
                continue;
            }

            $filter = new FgiscsBuildingResourcePriceChunkReadFilter();
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

    private function readWorksheetChunk(Worksheet $worksheet, int $sheetIndex, int $startRow): iterable
    {
        $highestColumn = $worksheet->getHighestColumn();
        $highestRow = $worksheet->getHighestRow();
        $headerRow = $this->detectHeaderRow($worksheet, $highestColumn);

        if ($headerRow !== null) {
            yield from $this->readDirectExportRows($worksheet, $highestColumn, $highestRow, $sheetIndex, max($headerRow + 1, $startRow));

            return;
        }

        yield from $this->readSplitFormRows($worksheet, $highestColumn, $highestRow, $sheetIndex, $startRow);
    }

    private function readDirectExportRows(Worksheet $worksheet, string $highestColumn, int $highestRow, int $sheetIndex, int $firstDataRow): iterable
    {
        for ($row = $firstDataRow; $row <= $highestRow; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $code = $this->clean((string) ($values[0] ?? ''));

            if (!$this->isMaterialCode($code)) {
                continue;
            }

            $name = $this->clean((string) ($values[1] ?? ''));
            $unit = $this->clean((string) ($values[2] ?? ''));
            $currentPrice = $this->toFloat($values[4] ?? null);

            if ($name === '' || $currentPrice === null || $currentPrice <= 0) {
                continue;
            }

            yield new FgiscsBuildingResourcePriceDTO(
                code: $code,
                name: $name,
                unit: $unit !== '' ? $unit : null,
                currentPrice: $currentPrice,
                sourcePriceKind: 'regional_building_resource_export',
                rawData: [
                    'sheet_index' => $sheetIndex,
                    'row_number' => $row,
                    'release_price' => $this->toFloat($values[3] ?? null),
                    'estimated_price' => $currentPrice,
                ],
            );
        }
    }

    private function readSplitFormRows(Worksheet $worksheet, string $highestColumn, int $highestRow, int $sheetIndex, int $startRow): iterable
    {
        for ($row = $startRow; $row <= $highestRow; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $code = $this->clean((string) ($values[0] ?? ''));

            if (!$this->isMaterialCode($code)) {
                continue;
            }

            $name = $this->clean((string) ($values[1] ?? ''));
            $unit = $this->clean((string) ($values[2] ?? ''));
            $basePrice = $this->toFloat($values[4] ?? null);
            $directPrice = $this->toFloat($values[7] ?? null);
            $groupIndex = $this->toFloat($values[8] ?? null);
            $currentPrice = $directPrice ?? ($basePrice !== null && $groupIndex !== null ? round($basePrice * $groupIndex, 4) : null);

            if ($name === '' || $currentPrice === null || $currentPrice <= 0) {
                continue;
            }

            yield new FgiscsBuildingResourcePriceDTO(
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
    }

    private function detectHeaderRow(Worksheet $worksheet, string $highestColumn): ?int
    {
        $limit = min($worksheet->getHighestRow(), 30);

        for ($row = 1; $row <= $limit; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $normalized = array_map(fn (mixed $value): string => $this->normalizeHeader((string) $value), $values);

            if (in_array('resource_code', $normalized, true) && in_array('estimated_price', $normalized, true)) {
                return $row;
            }
        }

        return null;
    }

    private function isMaterialCode(string $code): bool
    {
        return preg_match('/^\d{2}\.\d\.\d{2}\.\d{2}-\d{4}$/', $code) === 1;
    }

    private function normalizeHeader(string $value): string
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
            str_contains($value, 'кодстроительногоресурса') || $value === 'кодресурса' => 'resource_code',
            str_contains($value, 'сметнаяцена') => 'estimated_price',
            default => $value,
        };
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
        return $row <= 30 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}
