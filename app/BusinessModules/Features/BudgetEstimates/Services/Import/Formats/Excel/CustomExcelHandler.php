<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportValidationResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\RuntimeImportFormatHandlerInterface;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetAiColumnMapper;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetTableReader;
use App\Models\ImportSession;
use Generator;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class CustomExcelHandler implements RuntimeImportFormatHandlerInterface
{
    protected SpreadsheetAiColumnMapper $aiColumnMapper;

    public function __construct(
        protected SpreadsheetTableReader $reader,
        protected SpreadsheetHeaderDetector $headerDetector,
        ?SpreadsheetAiColumnMapper $aiColumnMapper = null,
    ) {
        $this->aiColumnMapper = $aiColumnMapper ?? new SpreadsheetAiColumnMapper();
    }

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
        $worksheets = $this->selectWorksheetsForImport($filePath, 60);
        $worksheet = $worksheets[0];
        $detection = $worksheet['detection'];
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
            candidates: $detection['header_candidates'] ?? [],
            metadata: $this->worksheetMetadata($worksheet, $worksheets) + [
                'header_row' => $detection['header_row'] ?? null,
            ],
            warnings: $confidence < 0.5 ? [trans_message('estimate.import_low_confidence')] : [],
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        $worksheets = $this->selectWorksheetsForImport($filePath, 80);
        $worksheet = $worksheets[0];

        return $this->structureFromRows(
            $worksheet['rows'],
            metadata: $this->worksheetMetadata($worksheet, $worksheets)
        );
    }

    public function detectStructureFromHeaderRow(ImportSession $session, string $filePath, int $headerRow): ImportStructureResult
    {
        $worksheets = $this->selectWorksheetsForImport($filePath, max(80, $headerRow + 20), $headerRow);
        $worksheet = $worksheets[0];

        return $this->structureFromRows(
            $worksheet['rows'],
            $headerRow,
            $this->worksheetMetadata($worksheet, $worksheets)
        );
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
                'worksheet_index' => $structure->metadata['worksheet_index'] ?? null,
                'worksheet_name' => $structure->metadata['worksheet_name'] ?? null,
                'worksheet_title' => $structure->metadata['worksheet_title'] ?? null,
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
        foreach ($this->worksheetDescriptorsFromStructure($structure) as $worksheet) {
            yield from $this->rowsToDtos(
                $this->reader->readRows($filePath, null, $worksheet['worksheet_index']),
                $this->structureForWorksheet($structure, $worksheet)
            );
        }

        yield from $this->additionalCostRowsToDtos($structure);
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     * @return Generator<int, EstimateImportRowDTO>
     */
    protected function rowsToDtos(array $rows, ImportStructureResult $structure): Generator
    {
        $headerRow = $structure->headerRow ?? 0;
        $mapping = $this->mappingForStructure($structure);
        $currentSectionPath = null;
        $currentRootSectionPath = null;
        $generatedSectionNumber = 0;
        $sectionLevelOffset = 0;

        foreach ($rows as $rowNumber => $row) {
            if ($rowNumber <= $headerRow) {
                $section = $this->detectStandaloneSectionRow($row);
                if ($section !== null) {
                    $sectionDto = $this->makeSectionRowDto(
                        (int) $rowNumber,
                        $row,
                        $section,
                        $currentRootSectionPath,
                        $generatedSectionNumber,
                        $sectionLevelOffset,
                    );
                    $currentSectionPath = $sectionDto->sectionPath;

                    yield $sectionDto;
                }

                continue;
            }

            if ($this->isEmptyRow($row) || $this->isRepeatedHeader($row, $mapping)) {
                continue;
            }

            $standaloneSection = $this->detectStandaloneSectionRow($row);
            if ($standaloneSection !== null) {
                $sectionDto = $this->makeSectionRowDto(
                    (int) $rowNumber,
                    $row,
                    $standaloneSection,
                    $currentRootSectionPath,
                    $generatedSectionNumber,
                    $sectionLevelOffset,
                );
                $currentSectionPath = $sectionDto->sectionPath;

                yield $sectionDto;
                continue;
            }

            $name = $this->value($row, $mapping['name'] ?? null);
            $code = $this->value($row, $mapping['code'] ?? null);
            $positionNumber = $this->value($row, $mapping['position_number'] ?? ($mapping['section_number'] ?? null));
            $quantity = $this->number($this->value($row, $mapping['quantity'] ?? null));
            $unitPrice = $this->number($this->value($row, $mapping['unit_price'] ?? null));
            $total = $this->number($this->value($row, $mapping['total_price'] ?? ($mapping['current_total_amount'] ?? null)));
            $section = $this->detectSectionRow($row, $name, $positionNumber, $code, $mapping, $quantity, $unitPrice, $total);

            if ($this->isFooterRow($name !== '' ? $name : $positionNumber)) {
                continue;
            }

            if ($name === '' && $code === '' && $section === null) {
                continue;
            }

            if ($section !== null) {
                $sectionDto = $this->makeSectionRowDto(
                    (int) $rowNumber,
                    $row,
                    $section,
                    $currentRootSectionPath,
                    $generatedSectionNumber,
                    $sectionLevelOffset,
                );
                $currentSectionPath = $sectionDto->sectionPath;

                yield $sectionDto;
                continue;
            }

            if ($name !== '' && $quantity === null && $unitPrice === null && $total === null && $code === '') {
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
                isSection: false,
                itemType: 'work',
                sectionPath: $currentSectionPath,
                rawData: $row,
                currentTotalAmount: $total,
            );
        }
    }

    private function makeSectionRowDto(
        int $rowNumber,
        array $row,
        array $section,
        ?string &$currentRootSectionPath,
        int &$generatedSectionNumber,
        int &$sectionLevelOffset,
    ): EstimateImportRowDTO {
        $sectionNumber = $section['number'];
        if ($sectionNumber === null || $sectionNumber === '') {
            $generatedSectionNumber++;
            $sectionNumber = (string) $generatedSectionNumber;
        }

        $sectionPath = $this->resolveSectionPath($section, $sectionNumber, $currentRootSectionPath);
        $level = (int) $section['level'] + $sectionLevelOffset;

        if (($section['number'] ?? null) === null && $sectionLevelOffset === 0) {
            $sectionLevelOffset = 1;
        }

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: $sectionNumber,
            itemName: $section['name'],
            isSection: true,
            itemType: 'section',
            level: $level,
            sectionPath: $sectionPath,
            rawData: $row,
        );
    }

    private function resolveSectionPath(array $section, string $sectionNumber, ?string &$currentRootSectionPath): string
    {
        $localPath = $this->cleanSectionPathPart(
            (string) ($section['path'] ?: (($section['number'] ?? null) === null ? $section['name'] : $sectionNumber))
        );

        if (($section['number'] ?? null) === null) {
            $currentRootSectionPath = $localPath;

            return $localPath;
        }

        if ($currentRootSectionPath !== null && $currentRootSectionPath !== '') {
            return "{$currentRootSectionPath}/{$localPath}";
        }

        return $localPath;
    }

    private function cleanSectionPathPart(string $value): string
    {
        $value = trim(str_replace(["\r", "\n", "\xc2\xa0"], ' ', $value));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, "/ \t\n\r\0\x0B");
    }

    protected function mappingForStructure(ImportStructureResult $structure): array
    {
        $mapping = $structure->columnMapping;
        if ($structure->rawHeaders === []) {
            return $mapping;
        }

        $detected = $this->headerDetector->mapHeaders($structure->rawHeaders);
        if ($this->mappingCoverageScore($detected) >= $this->mappingCoverageScore($mapping) + 20) {
            return $detected;
        }

        if (!$this->mappingHasRequiredColumns($mapping) && $this->mappingHasRequiredColumns($detected)) {
            return $detected;
        }

        return $mapping;
    }

    protected function mappingCoverageScore(array $mapping): int
    {
        $score = 0;
        $weights = [
            'name' => 30,
            'quantity' => 20,
            'unit_price' => 20,
            'total_price' => 20,
            'current_total_amount' => 20,
            'unit' => 10,
            'position_number' => 8,
            'section_number' => 8,
            'code' => 5,
        ];

        foreach ($weights as $field => $weight) {
            if (isset($mapping[$field])) {
                $score += $weight;
            }
        }

        return $score;
    }

    protected function mappingHasRequiredColumns(array $mapping): bool
    {
        return isset($mapping['name'])
            && (
                isset($mapping['quantity'])
                || isset($mapping['unit_price'])
                || isset($mapping['total_price'])
                || isset($mapping['current_total_amount'])
            );
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
        foreach (['quantity', 'unit_price', 'total_price', 'current_total_amount'] as $field) {
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

    protected function firstRowsText(string $filePath, ?int $maxRows = 40): string
    {
        $parts = [];

        foreach ($this->reader->readWorksheets($filePath, $maxRows) as $worksheet) {
            foreach ($worksheet['rows'] as $row) {
                $parts[] = implode(' ', array_map(
                    static fn (mixed $value): string => (string) $value,
                    $row
                ));
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     */
    protected function structureFromRows(
        array $rows,
        ?int $forcedHeaderRow = null,
        array $metadata = []
    ): ImportStructureResult
    {
        if ($forcedHeaderRow !== null) {
            $detection = $this->headerDetector->detectHeaderRow($rows, $forcedHeaderRow);
        } else {
            $detection = $this->headerDetector->detect($rows);
        }

        $aiStructure = $this->aiColumnMapper->detectStructure($rows, $detection);
        if (($aiStructure['applied'] ?? false) === true && is_array($aiStructure['detection'] ?? null)) {
            $detection = $aiStructure['detection'];
        }

        $headerRow = $detection['header_row'] !== null ? (int) $detection['header_row'] : null;
        $sampleRows = $this->sampleRows($rows, $headerRow);
        $aiMapping = $this->aiColumnMapper->improve(
            $detection['raw_headers'] ?? [],
            $sampleRows,
            $detection['column_mapping'] ?? [],
        );
        $resolvedMapping = is_array($aiMapping['mapping'] ?? null)
            ? $aiMapping['mapping']
            : ($detection['column_mapping'] ?? []);
        if (($aiMapping['applied'] ?? false) === true) {
            $detection['column_mapping'] = $resolvedMapping;
            $detection['detected_columns'] = array_flip($resolvedMapping);
        }

        return new ImportStructureResult(
            formatSlug: $this->slug(),
            headerRow: $headerRow,
            columnMapping: $detection['column_mapping'] ?? [],
            detectedColumns: $detection['detected_columns'] ?? [],
            rawHeaders: $detection['raw_headers'] ?? [],
            sampleRows: $sampleRows,
            headerCandidates: $detection['header_candidates'] ?? [],
            warnings: $headerRow === null ? [trans_message('estimate.import_header_not_found')] : [],
            metadata: $metadata + [
                'column_mapping_source' => ($aiMapping['applied'] ?? false) === true || ($aiStructure['applied'] ?? false) === true ? 'ai' : 'rules',
                'ai_structure_reason' => $aiStructure['reason'] ?? null,
                'ai_structure_confidence' => $aiStructure['confidence'] ?? null,
                'ai_structure_model' => $aiStructure['model'] ?? null,
                'ai_mapping_reason' => $aiMapping['reason'] ?? null,
                'ai_mapping_confidence' => $aiMapping['confidence'] ?? null,
                'ai_mapping_model' => $aiMapping['model'] ?? null,
            ],
            aiMappingApplied: ($aiMapping['applied'] ?? false) === true || ($aiStructure['applied'] ?? false) === true,
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
     * @return array<int, array{index: int, name: string, rows: array<int, array<int, mixed>>, detection: array<string, mixed>, score: int, confidence: float}>
     */
    private function selectWorksheetsForImport(string $filePath, ?int $maxRows, ?int $forcedHeaderRow = null): array
    {
        $worksheets = $this->reader->readWorksheets($filePath, $maxRows);
        $candidates = [];

        foreach ($worksheets as $worksheet) {
            $detection = $forcedHeaderRow !== null
                ? $this->headerDetector->detectHeaderRow($worksheet['rows'], $forcedHeaderRow)
                : $this->headerDetector->detect($worksheet['rows']);
            $mapping = $detection['column_mapping'] ?? [];
            $candidate = [
                'index' => $worksheet['index'],
                'name' => $worksheet['name'],
                'rows' => $worksheet['rows'],
                'detection' => $detection,
                'score' => (int) ($detection['score'] ?? 0),
                'confidence' => is_array($mapping)
                    ? $this->confidenceFromMapping($mapping, (int) ($detection['score'] ?? 0))
                    : 0.0,
            ];

            $candidates[] = $candidate;
        }

        usort($candidates, static function (array $left, array $right): int {
            $confidenceComparison = $right['confidence'] <=> $left['confidence'];
            if ($confidenceComparison !== 0) {
                return $confidenceComparison;
            }

            return $right['score'] <=> $left['score'];
        });

        $selected = array_values(array_filter($candidates, function (array $candidate): bool {
            $mapping = $candidate['detection']['column_mapping'] ?? [];

            return $candidate['confidence'] > 0.0
                && $candidate['score'] >= 60
                && is_array($mapping)
                && $this->mappingHasRequiredColumns($mapping);
        }));

        if ($selected !== []) {
            return $this->withAdditionalCostRows($selected, $candidates);
        }

        if ($candidates !== []) {
            return [$candidates[0]];
        }

        return [[
            'index' => 0,
            'name' => '',
            'rows' => [],
            'detection' => $this->headerDetector->detect([]),
            'score' => 0,
            'confidence' => 0.0,
        ]];
    }

    private function withAdditionalCostRows(array $selected, array $candidates): array
    {
        $additionalCostRows = $this->detectAdditionalCostRows($candidates, $selected);
        if ($additionalCostRows !== []) {
            $selected[0]['additional_cost_rows'] = $additionalCostRows;
        }

        return $selected;
    }

    private function detectAdditionalCostRows(array $candidates, array $selected): array
    {
        $selectedIndexes = array_fill_keys(
            array_map(static fn (array $candidate): int => (int) $candidate['index'], $selected),
            true
        );
        $selectedNames = array_values(array_filter(array_map(
            fn (array $candidate): string => $this->normalizeTextForMatching((string) $candidate['name']),
            $selected
        )));
        $selectedTotals = array_values(array_filter(array_map(
            fn (array $candidate): ?float => $this->worksheetItemsTotal($candidate),
            $selected
        ), static fn (?float $total): bool => $total !== null && $total > 0.0));
        $additionalCostRows = [];

        foreach ($candidates as $candidate) {
            if (isset($selectedIndexes[(int) $candidate['index']])) {
                continue;
            }

            foreach ($candidate['rows'] as $rowNumber => $row) {
                $costLine = $this->costLineFromRow($row, (int) $rowNumber, $candidate);
                if ($costLine === null) {
                    continue;
                }

                if ($this->shouldSkipTitleCostLine($costLine['item_name'])) {
                    continue;
                }

                if ($this->matchesSelectedWorksheetTotal($costLine, $selectedNames, $selectedTotals)) {
                    continue;
                }

                $additionalCostRows[] = $costLine;
            }
        }

        return $additionalCostRows;
    }

    private function worksheetItemsTotal(array $candidate): ?float
    {
        $mapping = $candidate['detection']['column_mapping'] ?? [];
        $headerRow = $candidate['detection']['header_row'] ?? null;
        if (!is_array($mapping) || $headerRow === null) {
            return null;
        }

        $total = 0.0;
        foreach ($candidate['rows'] as $rowNumber => $row) {
            if ((int) $rowNumber <= (int) $headerRow) {
                continue;
            }

            if ($this->isEmptyRow($row) || $this->detectStandaloneSectionRow($row) !== null) {
                continue;
            }

            $name = $this->value($row, $mapping['name'] ?? null);
            $positionNumber = $this->value($row, $mapping['position_number'] ?? ($mapping['section_number'] ?? null));
            $code = $this->value($row, $mapping['code'] ?? null);
            if ($name === '' && $positionNumber === '' && $code === '') {
                continue;
            }

            if ($this->isFooterRow($name !== '' ? $name : $positionNumber)) {
                continue;
            }

            $amount = $this->number($this->value($row, $mapping['total_price'] ?? ($mapping['current_total_amount'] ?? null)));
            if ($amount !== null) {
                $total += $amount;
            }
        }

        return $total > 0.0 ? $total : null;
    }

    private function costLineFromRow(array $row, int $rowNumber, array $worksheet): ?array
    {
        $textParts = [];
        $amount = null;

        foreach ($row as $value) {
            $text = trim(str_replace(["\r", "\n", "\xc2\xa0"], ' ', (string) $value));
            if ($text === '') {
                continue;
            }

            if ($this->looksLikeAmountCell($value, $text)) {
                $parsed = $this->number($text);
                if ($parsed !== null) {
                    $amount = $parsed;
                    continue;
                }
            }

            $textParts[] = $text;
        }

        $label = trim(implode(' ', $textParts));
        if ($label === '' || $amount === null || $amount <= 0.0) {
            return null;
        }

        return [
            'row_number' => $rowNumber,
            'item_name' => $label,
            'amount' => $amount,
            'worksheet_index' => (int) $worksheet['index'],
            'worksheet_name' => (string) $worksheet['name'],
        ];
    }

    private function looksLikeAmountCell(mixed $value, string $text): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        return preg_match('/^-?\d[\d\s.,]*(?:\p{Sc}|руб\.?|р\.?)?$/iu', $text) === 1;
    }

    private function shouldSkipTitleCostLine(string $label): bool
    {
        $normalized = $this->normalizeTextForMatching($label);

        if ($normalized === '') {
            return true;
        }

        if (
            str_starts_with($normalized, 'итого')
            || str_starts_with($normalized, 'всего')
            || str_starts_with($normalized, 'total')
            || str_contains($normalized, 'в том числе')
        ) {
            return true;
        }

        return in_array($normalized, [
            'работы',
            'материалы',
            'материалы и пр',
            'work',
            'works',
            'materials',
        ], true);
    }

    private function matchesSelectedWorksheetTotal(array $costLine, array $selectedNames, array $selectedTotals): bool
    {
        $label = $this->normalizeTextForMatching((string) $costLine['item_name']);

        foreach ($selectedNames as $selectedName) {
            if (
                $selectedName !== ''
                && (
                    str_contains($label, $selectedName)
                    || $this->titleLineReferencesWorksheet($label, $selectedName)
                )
            ) {
                return true;
            }
        }

        foreach ($selectedTotals as $selectedTotal) {
            if (abs((float) $costLine['amount'] - (float) $selectedTotal) < 0.01) {
                return true;
            }
        }

        return false;
    }

    private function titleLineReferencesWorksheet(string $label, string $selectedName): bool
    {
        if (!str_starts_with($label, 'стоимость') && !str_starts_with($label, 'cost')) {
            return false;
        }

        return array_intersect($this->tokenStems($label), $this->tokenStems($selectedName)) !== [];
    }

    private function tokenStems(string $value): array
    {
        $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static function (string $token): ?string {
                if (mb_strlen($token) < 4) {
                    return null;
                }

                return mb_substr($token, 0, 5);
            },
            $tokens
        ))));
    }

    private function additionalCostRowsFromSelection(array $worksheets): array
    {
        $additionalCostRows = [];
        foreach ($worksheets as $worksheet) {
            if (is_array($worksheet['additional_cost_rows'] ?? null)) {
                array_push($additionalCostRows, ...$worksheet['additional_cost_rows']);
            }
        }

        return $additionalCostRows;
    }

    private function additionalCostRowsToDtos(ImportStructureResult $structure): Generator
    {
        $rows = $structure->metadata['additional_cost_rows'] ?? [];
        if (!is_array($rows) || $rows === []) {
            return;
        }

        $sectionName = trans_message('estimate.import_additional_costs_section');
        if ($sectionName === 'estimate.import_additional_costs_section') {
            $sectionName = 'Дополнительные расходы';
        }

        $firstRowNumber = isset($rows[0]['row_number']) ? (int) $rows[0]['row_number'] : 0;

        yield new EstimateImportRowDTO(
            rowNumber: max(1, $firstRowNumber - 1),
            sectionNumber: null,
            itemName: $sectionName,
            isSection: true,
            itemType: 'section',
            level: 1,
            sectionPath: $sectionName,
            rawData: ['source' => 'title_sheet'],
        );

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $amount = isset($row['amount']) ? (float) $row['amount'] : null;
            if ($amount === null || $amount <= 0.0) {
                continue;
            }

            yield new EstimateImportRowDTO(
                rowNumber: isset($row['row_number']) ? (int) $row['row_number'] : 0,
                sectionNumber: null,
                itemName: (string) ($row['item_name'] ?? ''),
                unit: null,
                quantity: 1.0,
                unitPrice: $amount,
                isSection: false,
                itemType: 'work',
                sectionPath: $sectionName,
                rawData: array_merge($row, ['disable_sub_item_grouping' => true]),
                currentTotalAmount: $amount,
            );
        }
    }

    private function normalizeTextForMatching(string $value): string
    {
        $value = mb_strtolower($value);
        $value = str_replace(["\r", "\n", "\t", "\xc2\xa0"], ' ', $value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * @param array{index: int, name: string} $worksheet
     * @param array<int, array{index: int, name: string, detection: array<string, mixed>, score: int, confidence: float}> $worksheets
     * @return array<string, mixed>
     */
    private function worksheetMetadata(array $worksheet, array $worksheets = []): array
    {
        $worksheets = $worksheets !== [] ? $worksheets : [$worksheet];
        $additionalCostRows = $this->additionalCostRowsFromSelection($worksheets);

        return [
            'worksheet_index' => $worksheet['index'],
            'worksheet_name' => $worksheet['name'],
            'worksheet_title' => $worksheet['name'],
            'additional_cost_rows' => $additionalCostRows,
            'worksheet_indices' => array_map(
                static fn (array $item): int => (int) $item['index'],
                $worksheets
            ),
            'worksheets' => array_map(
                static fn (array $item): array => [
                    'worksheet_index' => (int) $item['index'],
                    'worksheet_name' => (string) $item['name'],
                    'worksheet_title' => (string) $item['name'],
                    'header_row' => $item['detection']['header_row'] ?? null,
                    'score' => (int) $item['score'],
                    'confidence' => (float) $item['confidence'],
                    'column_mapping' => $item['detection']['column_mapping'] ?? [],
                    'detected_columns' => $item['detection']['detected_columns'] ?? [],
                    'raw_headers' => $item['detection']['raw_headers'] ?? [],
                ],
                $worksheets
            ),
        ];
    }

    /**
     * @return array<int, array{worksheet_index: ?int, worksheet_name: ?string, header_row: ?int, column_mapping: array<string, string>, detected_columns: array<string, string>, raw_headers: array<int, mixed>}>
     */
    private function worksheetDescriptorsFromStructure(ImportStructureResult $structure): array
    {
        $worksheets = $structure->metadata['worksheets'] ?? null;
        if (is_array($worksheets) && $worksheets !== []) {
            return array_values(array_filter(array_map(
                function (mixed $worksheet) use ($structure): ?array {
                    if (!is_array($worksheet)) {
                        return null;
                    }

                    $index = $this->normalizeWorksheetIndex($worksheet['worksheet_index'] ?? null);
                    if ($index === null) {
                        return null;
                    }

                    $mapping = is_array($worksheet['column_mapping'] ?? null)
                        ? ImportStructureResult::columnMappingFromArray(['column_mapping' => $worksheet['column_mapping']])
                        : [];

                    return [
                        'worksheet_index' => $index,
                        'worksheet_name' => is_string($worksheet['worksheet_name'] ?? null) ? $worksheet['worksheet_name'] : null,
                        'header_row' => isset($worksheet['header_row']) ? (int) $worksheet['header_row'] : $structure->headerRow,
                        'column_mapping' => $mapping,
                        'detected_columns' => is_array($worksheet['detected_columns'] ?? null) ? $worksheet['detected_columns'] : [],
                        'raw_headers' => is_array($worksheet['raw_headers'] ?? null) ? $worksheet['raw_headers'] : [],
                    ];
                },
                $worksheets
            )));
        }

        return [[
            'worksheet_index' => $this->normalizeWorksheetIndex($structure->metadata['worksheet_index'] ?? null),
            'worksheet_name' => is_string($structure->metadata['worksheet_name'] ?? null) ? $structure->metadata['worksheet_name'] : null,
            'header_row' => $structure->headerRow,
            'column_mapping' => $structure->columnMapping,
            'detected_columns' => $structure->detectedColumns,
            'raw_headers' => $structure->rawHeaders,
        ]];
    }

    /**
     * @param array{worksheet_index: ?int, worksheet_name: ?string, header_row: ?int, column_mapping: array<string, string>, detected_columns: array<string, string>, raw_headers: array<int, mixed>} $worksheet
     */
    private function structureForWorksheet(ImportStructureResult $structure, array $worksheet): ImportStructureResult
    {
        $mapping = $structure->columnMapping !== [] ? $structure->columnMapping : $worksheet['column_mapping'];
        $detectedColumns = $mapping !== []
            ? ImportStructureResult::detectedColumnsFromMapping($mapping)
            : ($worksheet['detected_columns'] !== [] ? $worksheet['detected_columns'] : $structure->detectedColumns);

        return new ImportStructureResult(
            formatSlug: $structure->formatSlug,
            headerRow: $worksheet['header_row'],
            columnMapping: $mapping,
            detectedColumns: $detectedColumns,
            rawHeaders: $worksheet['raw_headers'] !== [] ? $worksheet['raw_headers'] : $structure->rawHeaders,
            sampleRows: $structure->sampleRows,
            headerCandidates: $structure->headerCandidates,
            rowStyles: $structure->rowStyles,
            warnings: $structure->warnings,
            metadata: array_merge($structure->metadata, [
                'worksheet_index' => $worksheet['worksheet_index'],
                'worksheet_name' => $worksheet['worksheet_name'],
                'worksheet_title' => $worksheet['worksheet_name'],
            ]),
            aiMappingApplied: $structure->aiMappingApplied,
        );
    }

    private function normalizeWorksheetIndex(mixed $index): ?int
    {
        if (is_int($index)) {
            return $index;
        }

        return is_string($index) && ctype_digit($index) ? (int) $index : null;
    }

    /**
     * @param array<int, mixed> $row
     */
    private function detectStandaloneSectionRow(array $row): ?array
    {
        $values = array_values(array_filter(
            array_map(
                static fn (mixed $value): string => trim(str_replace(["\r", "\n", "\xc2\xa0"], ' ', (string) $value)),
                $row
            ),
            static fn (string $value): bool => $value !== ''
        ));

        if (count($values) !== 1) {
            return null;
        }

        $text = $values[0];
        if ($this->isFooterRow($text) || $this->looksLikeHeaderText($text) || mb_strlen($text) > 160) {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)*)\.?\s+(.+)$/u', $text, $match) === 1) {
            return $this->sectionCandidate((string) $match[1], (string) $match[2]);
        }

        if (preg_match('/\d+(?:[\s.,]\d+)?\s*(?:₽|руб\.?)/iu', $text) === 1) {
            return null;
        }

        return $this->sectionCandidate(null, $text);
    }

    private function looksLikeHeaderText(string $text): bool
    {
        $normalized = mb_strtolower($text);

        return str_contains($normalized, 'наименование')
            || str_contains($normalized, 'кол-во')
            || str_contains($normalized, 'количество')
            || str_contains($normalized, 'ед. изм')
            || str_contains($normalized, 'цена');
    }

    /**
     * @param array<int, mixed> $row
     * @param array<string, string> $mapping
     */
    protected function detectSectionRow(
        array $row,
        string $name,
        string $positionNumber,
        string $code,
        array $mapping,
        ?float $quantity,
        ?float $unitPrice,
        ?float $total
    ): ?array
    {
        $unit = $this->value($row, $mapping['unit'] ?? null);
        $hasMoneyOrQuantity = $quantity !== null || $unitPrice !== null || $total !== null;
        $nameText = trim($name);
        $positionText = trim($positionNumber);

        if ($hasMoneyOrQuantity || $unit !== '' || $code !== '') {
            return null;
        }

        foreach ([$nameText, $positionText] as $text) {
            $explicit = $this->parseExplicitSectionTitle($text, $positionText);
            if ($explicit !== null) {
                return $explicit;
            }
        }

        if ($nameText !== '' && $this->looksLikeSectionNumber($positionText)) {
            return $this->sectionCandidate($positionText, $nameText);
        }

        if ($nameText !== '' && $positionText === '') {
            return $this->sectionCandidate(null, $nameText);
        }

        return null;
    }

    private function parseExplicitSectionTitle(string $text, string $positionNumber): ?array
    {
        if ($text === '') {
            return null;
        }

        if (preg_match('/^(раздел|глава|объект|локальная смета|смета)\s*(?:№|N|No\.?)?\s*(\d+(?:\.\d+)*)?[.\s:–—-]*(.*)$/iu', $text, $match) !== 1) {
            return null;
        }

        $number = trim((string) ($match[2] ?? ''));
        if ($number === '' && $this->looksLikeSectionNumber($positionNumber)) {
            $number = $positionNumber;
        }

        $title = trim((string) ($match[3] ?? ''));
        if ($title === '') {
            $title = trim($text);
        }

        return $this->sectionCandidate($number !== '' ? $number : null, $title);
    }

    private function sectionCandidate(?string $number, string $name): array
    {
        $normalizedNumber = $number !== null ? trim($number, ". \t\n\r\0\x0B") : null;
        $sectionName = trim($name, ". \t\n\r\0\x0B");

        return [
            'number' => $normalizedNumber,
            'name' => $sectionName !== '' ? $sectionName : ($normalizedNumber ?? ''),
            'path' => $normalizedNumber,
            'level' => $normalizedNumber !== null ? substr_count($normalizedNumber, '.') + 1 : 1,
        ];
    }

    private function looksLikeSectionNumber(string $value): bool
    {
        return preg_match('/^\d+(?:\.\d+)*\.?$/u', trim($value)) === 1;
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

        $normalized = str_replace([" ", "\xc2\xa0"], '', $value);
        $normalized = preg_replace('/[^\d,.\-]/u', '', $normalized) ?? '';
        if ($normalized === '' || preg_match('/\d/u', $normalized) !== 1) {
            return null;
        }

        $lastComma = strrpos($normalized, ',');
        $lastDot = strrpos($normalized, '.');

        if ($lastComma !== false && $lastDot !== false) {
            $decimalSeparator = $lastComma > $lastDot ? ',' : '.';
            $thousandsSeparator = $decimalSeparator === ',' ? '.' : ',';
            $normalized = str_replace($thousandsSeparator, '', $normalized);
            $normalized = str_replace($decimalSeparator, '.', $normalized);
        } elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $parts = explode($separator, $normalized);
            $fraction = end($parts);

            if (count($parts) === 2 && strlen((string) $fraction) === 3) {
                $normalized = implode('', $parts);
            } else {
                $normalized = str_replace($separator, '.', $normalized);
            }
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    protected function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
