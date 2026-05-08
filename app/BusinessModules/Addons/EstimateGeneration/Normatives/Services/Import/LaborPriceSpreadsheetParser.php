<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\LaborPriceDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Enums\EstimateResourceType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class LaborPriceSpreadsheetParser
{
    private const CHUNK_SIZE = 1000;

    public function parse(string $filePath): iterable
    {
        $reader = IOFactory::createReader(IOFactory::identify($filePath));
        $reader->setReadDataOnly(true);

        $worksheetInfo = $reader->listWorksheetInfo($filePath);

        foreach ($worksheetInfo as $sheetIndex => $sheetInfo) {
            $totalRows = (int) ($sheetInfo['totalRows'] ?? 0);

            if ($totalRows < 2) {
                continue;
            }

            $filter = new LaborPriceChunkReadFilter();
            $reader->setReadFilter($filter);
            $reader->setLoadSheetsOnly([(string) $sheetInfo['worksheetName']]);

            for ($startRow = 1; $startRow <= $totalRows; $startRow += self::CHUNK_SIZE) {
                $filter->setRows($startRow, self::CHUNK_SIZE);
                $spreadsheet = $reader->load($filePath);
                $worksheet = $spreadsheet->getSheet(0);

                foreach ($this->readWorksheetChunk($worksheet, $sheetIndex, $startRow) as $dto) {
                    yield $dto;
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
        $headers = $this->headers($worksheet, $highestColumn);

        if ($headers === []) {
            return;
        }

        $firstDataRow = max(2, $startRow);

        for ($row = $firstDataRow; $row <= $highestRow; $row++) {
            $values = $worksheet->rangeToArray("A{$row}:{$highestColumn}{$row}", null, true, false)[0] ?? [];
            $rowData = $this->rowData($headers, $values);
            $code = $this->first($rowData, ['code', 'kod', 'shifr']);
            $name = $this->first($rowData, ['name', 'naimenovanie', 'resurs']);
            $unit = $this->first($rowData, ['unit', 'edizm', 'edinitsaizmereniya']);
            $price = $this->toFloat($this->first($rowData, ['price', 'tsena', 'smetnayatsena', 'baseprice', 'stoimost']));

            if ($code === null || $name === null || $price === null) {
                continue;
            }

            yield new LaborPriceDTO(
                code: $this->normalizeCode($code),
                name: $name,
                unit: $unit,
                basePrice: $price,
                resourceType: $this->classify($code, $name),
                rawData: [
                    'sheet_index' => $sheetIndex,
                    'row_number' => $row,
                    'row' => $rowData,
                ],
            );
        }
    }

    private function headers(Worksheet $worksheet, string $highestColumn): array
    {
        $values = $worksheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];
        $headers = [];

        foreach ($values as $index => $value) {
            $normalized = $this->normalizeHeader((string) $value);

            if ($normalized !== '') {
                $headers[$index] = $normalized;
            }
        }

        return $headers;
    }

    private function rowData(array $headers, array $values): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            $row[$header] = $this->clean((string) ($values[$index] ?? ''));
        }

        return $row;
    }

    private function first(array $row, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            if (($row[$alias] ?? '') !== '') {
                return $row[$alias];
            }
        }

        return null;
    }

    private function classify(string $code, string $name): string
    {
        $normalizedName = mb_strtolower($name);

        if (preg_match('/^4-100-\d+$/', $code) === 1 || str_contains($normalizedName, 'машинист')) {
            return EstimateResourceType::MACHINE_LABOR->value;
        }

        return EstimateResourceType::LABOR->value;
    }

    private function normalizeCode(string $code): string
    {
        return trim(preg_replace('/\s+/u', ' ', $code) ?? $code);
    }

    private function normalizeHeader(string $value): string
    {
        $value = strtr(mb_strtolower(trim($value)), [
            'ё' => 'е',
            ' ' => '',
            '.' => '',
            '-' => '',
            '_' => '',
            '/' => '',
            '\\' => '',
            ',' => '',
            '(' => '',
            ')' => '',
        ]);

        return match ($value) {
            'код', 'шифр', 'resourcecode' => 'code',
            'наименование', 'название', 'ресурс' => 'name',
            'едизм', 'единицаизмерения', 'ед', 'unit' => 'unit',
            'цена', 'сметнаяцена', 'baseprice', 'стоимость' => 'price',
            default => $value,
        };
    }

    private function toFloat(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $value);

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }
}

final class LaborPriceChunkReadFilter implements IReadFilter
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
        return $row === 1 || ($row >= $this->startRow && $row <= $this->endRow);
    }
}
