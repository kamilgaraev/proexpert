<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportValidationResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\ImportSession;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class CustomExcelHandler implements RuntimeImportFormatHandlerInterface
{
    public function __construct(
        protected SpreadsheetTableReader $reader,
        protected SpreadsheetHeaderDetector $headerDetector,
    ) {}

    public function slug(): string
    {
        return 'custom_excel';
    }

    public function label(): string
    {
        return 'Произвольная Excel-смета';
    }

    public function supportedExtensions(): array
    {
        return ['xlsx', 'xls', 'xlsm'];
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $detection = $this->headerDetector->detect($this->reader->readRows($filePath, 60));
        $mapping = $detection['column_mapping'] ?? [];
        $score = (int) ($detection['score'] ?? 0);
        $confidence = $this->confidenceFromMapping($mapping, $score);

        return new ImportDetectionResult(
            detectedType: 'custom',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: $confidence,
            requiresConfirmation: true,
            indicators: $this->indicatorsFromMapping($mapping),
            warnings: $confidence < 0.5 ? [trans_message('estimate.import_low_confidence')] : [],
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        $rows = $this->reader->readRows($filePath, 80);

        return $this->structureFromRows($rows);
    }

    public function detectStructureFromHeaderRow(ImportSession $session, string $filePath, int $headerRow): ImportStructureResult
    {
        $rows = $this->reader->readRows($filePath, max(80, $headerRow + 20));

        return $this->structureFromRows($rows, $headerRow);
    }

    public function preview(ImportSession $session, string $filePath, ImportStructureResult $structure): ImportPreviewResult
    {
        $sections = [];
        $items = [];
        $totalAmount = 0.0;

        foreach ($this->streamRows($session, $filePath, $structure) as $row) {
            $payload = $row->toArray();

            if ($row->isSection) {
                $sections[] = $payload;
                continue;
            }

            $items[] = $payload;
            $totalAmount += (float) ($row->currentTotalAmount ?? (($row->quantity ?? 0) * ($row->unitPrice ?? 0)));
        }

        return new ImportPreviewResult(
            formatSlug: $this->slug(),
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => $totalAmount,
                'items_count' => count($items),
                'sections_count' => count($sections),
            ],
            validation: $this->validate($session, new ImportPreviewResult($this->slug(), $sections, $items))->toArray(),
            summary: [
                'rows_count' => count($sections) + count($items),
            ],
            metadata: [
                'handler' => $this->slug(),
                'header_row' => $structure->headerRow,
            ],
        );
    }

    public function validate(ImportSession $session, ImportPreviewResult $preview): ImportValidationResult
    {
        $errors = [];
        $warnings = [];

        if ($preview->items === []) {
            $errors[] = trans_message('estimate.import_empty_preview');
        }

        if ($preview->sections === []) {
            $warnings[] = trans_message('estimate.import_sections_not_found');
        }

        return new ImportValidationResult(
            errors: $errors,
            warnings: $warnings,
            summary: [
                'items_count' => count($preview->items),
                'sections_count' => count($preview->sections),
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        yield from $this->rowsToDtos($this->reader->readRows($filePath), $structure);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return Generator<int, EstimateImportRowDTO>
     */
    protected function rowsToDtos(array $rows, ImportStructureResult $structure): Generator
    {
        $headerRow = $structure->headerRow ?? 0;
        $mapping = $structure->columnMapping;

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $headerRow || $this->isEmptyRow($row) || $this->isRepeatedHeader($row, $mapping)) {
                continue;
            }

            $name = $this->value($row, $mapping['name'] ?? null);
            $code = $this->value($row, $mapping['code'] ?? null);
            $positionNumber = $this->value($row, $mapping['position_number'] ?? null);

            if ($name === '' && $code === '') {
                continue;
            }

            if ($this->isFooterRow($name)) {
                continue;
            }

            $isSection = $this->isSectionRow($row, $name, $positionNumber, $mapping);
            $quantity = $this->number($this->value($row, $mapping['quantity'] ?? null));
            $unitPrice = $this->number($this->value($row, $mapping['unit_price'] ?? null));
            $total = $this->number($this->value($row, $mapping['total_price'] ?? null));

            if (!$isSection && $name !== '' && $quantity === null && $unitPrice === null && $total === null && $code === '') {
                continue;
            }

            yield new EstimateImportRowDTO(
                rowNumber: (int) $rowNumber,
                sectionNumber: $positionNumber !== '' ? $positionNumber : null,
                itemName: $name !== '' ? $name : $code,
                unit: $this->nullable($this->value($row, $mapping['unit'] ?? null)),
                quantity: $quantity,
                unitPrice: $unitPrice,
                code: $this->nullable($code),
                isSection: $isSection,
                itemType: 'work',
                rawData: $row,
                currentTotalAmount: $total,
            );
        }
    }

    /**
     * @param array<string, string> $mapping
     */
    protected function confidenceFromMapping(array $mapping, int $score): float
    {
        if (!isset($mapping['name'])) {
            return 0.0;
        }

        $required = 0;
        foreach (['quantity', 'unit_price', 'total_price'] as $field) {
            if (isset($mapping[$field])) {
                $required++;
            }
        }

        return min(0.85, max(0.2, ($score / 100) + ($required * 0.12)));
    }

    /**
     * @param array<string, string> $mapping
     * @return array<int, string>
     */
    protected function indicatorsFromMapping(array $mapping): array
    {
        return array_map(
            static fn (string $field): string => "column_{$field}",
            array_keys($mapping)
        );
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    protected function structureFromRows(array $rows, ?int $forcedHeaderRow = null): ImportStructureResult
    {
        if ($forcedHeaderRow !== null) {
            $rawHeaders = $rows[$forcedHeaderRow] ?? [];
            $mapping = $this->headerDetector->mapHeaders($rawHeaders);
            $detection = [
                'header_row' => $forcedHeaderRow,
                'column_mapping' => $mapping,
                'detected_columns' => array_flip($mapping),
                'raw_headers' => $rawHeaders,
                'header_candidates' => [[
                    'row_index' => $forcedHeaderRow,
                    'score' => count($mapping) * 10,
                    'headers' => $rawHeaders,
                    'mapping' => $mapping,
                ]],
            ];
        } else {
            $detection = $this->headerDetector->detect($rows);
        }

        $headerRow = $detection['header_row'] !== null ? (int) $detection['header_row'] : null;

        return new ImportStructureResult(
            formatSlug: $this->slug(),
            headerRow: $headerRow,
            columnMapping: $detection['column_mapping'] ?? [],
            detectedColumns: $detection['detected_columns'] ?? [],
            rawHeaders: $detection['raw_headers'] ?? [],
            sampleRows: $this->sampleRows($rows, $headerRow),
            headerCandidates: $detection['header_candidates'] ?? [],
            warnings: $headerRow === null ? [trans_message('estimate.import_header_not_found')] : [],
        );
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return array<int, array<int, mixed>>
     */
    protected function sampleRows(array $rows, ?int $headerRow): array
    {
        if ($headerRow === null) {
            return array_slice(array_values($rows), 0, 8);
        }

        return array_slice(array_values(array_filter(
            $rows,
            static fn (array $row, int $rowNumber): bool => $rowNumber > $headerRow && array_filter($row) !== [],
            ARRAY_FILTER_USE_BOTH
        )), 0, 8);
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, string> $mapping
     */
    protected function isSectionRow(array $row, string $name, string $positionNumber, array $mapping): bool
    {
        $normalized = mb_strtolower($name);

        if (preg_match('/^(раздел|глава|объект|локальная смета|смета)\b/u', $normalized) === 1) {
            return true;
        }

        $quantity = $this->value($row, $mapping['quantity'] ?? null);
        $unitPrice = $this->value($row, $mapping['unit_price'] ?? null);
        $total = $this->value($row, $mapping['total_price'] ?? null);

        return $positionNumber === '' && $name !== '' && $quantity === '' && $unitPrice === '' && $total === '';
    }

    protected function isFooterRow(string $name): bool
    {
        $normalized = mb_strtolower($name);

        return str_starts_with($normalized, 'итого')
            || str_starts_with($normalized, 'всего')
            || str_contains($normalized, 'накладные расходы')
            || str_contains($normalized, 'сметная прибыль');
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, string> $mapping
     */
    protected function isRepeatedHeader(array $row, array $mapping): bool
    {
        $name = mb_strtolower($this->value($row, $mapping['name'] ?? null));

        return $name !== '' && str_contains($name, 'наименование');
    }

    /**
     * @param array<int, mixed> $row
     */
    protected function isEmptyRow(array $row): bool
    {
        return array_filter($row, static fn (mixed $value): bool => trim((string) $value) !== '') === [];
    }

    /**
     * @param array<int, mixed> $row
     */
    protected function value(array $row, ?string $column): string
    {
        if ($column === null || $column === '') {
            return '';
        }

        $index = Coordinate::columnIndexFromString($column) - 1;
        $value = $row[$index] ?? '';

        if (is_float($value) || is_int($value)) {
            return (string) $value;
        }

        return trim(str_replace(["\r", "\n", "\xc2\xa0"], ' ', (string) $value));
    }

    protected function number(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/-?\d+(?:[\s.,]\d+)?/u', str_replace(' ', '', $value), $match) !== 1) {
            return null;
        }

        return (float) str_replace(',', '.', $match[0]);
    }

    protected function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
