<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaSpreadsheetLoader;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetSampleLoader;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetHeaderDetector;
use App\Models\ImportSession;
use Generator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

final readonly class GrandSmetaRuntimeBridge implements RuntimeImportFormatHandlerInterface
{
    public function __construct(
        private GrandSmetaHandler $spreadsheetHandler,
        private GrandSmetaParser $spreadsheetParser,
        private GrandSmetaXMLParser $xmlParser,
    ) {}

    public function slug(): string
    {
        return 'grandsmeta';
    }

    public function label(): string
    {
        return 'Гранд-Смета';
    }

    public function supportedExtensions(): array
    {
        return ['xlsx', 'xls', 'xlsm', 'xml', 'gsfx'];
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xml', 'gsfx'], true)) {
            $content = file_get_contents($filePath) ?: '';
            $isGrandSmeta = str_contains($content, 'Generator="GrandSmeta"')
                || str_contains($content, '<GrandSmeta')
                || str_contains($content, 'GrandSmetaSign=');

            return new ImportDetectionResult(
                detectedType: $isGrandSmeta ? 'grandsmeta' : 'unknown',
                formatSlug: $this->slug(),
                label: $this->label(),
                confidence: $isGrandSmeta ? 1.0 : 0.0,
                indicators: $isGrandSmeta ? ['xml_generator_grandsmeta'] : [],
            );
        }

        $content = (new SpreadsheetSampleLoader)->load($filePath, 60);
        try {
            $handlerResult = $this->spreadsheetHandler->canHandle($content, $extension);
        } finally {
            $content->disconnectWorksheets();
        }

        return new ImportDetectionResult(
            detectedType: $handlerResult->detectedType,
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: $handlerResult->confidence,
            indicators: array_values($handlerResult->indicators),
            candidates: $handlerResult->candidates,
            metadata: $handlerResult->metadata,
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xml', 'gsfx'], true)) {
            $structure = $this->xmlParser->detectStructure($filePath);

            return new ImportStructureResult(
                formatSlug: $this->slug(),
                headerRow: $structure['header_row'] ?? null,
                columnMapping: $structure['column_mapping'] ?? [],
                detectedColumns: $structure['detected_columns'] ?? [],
                rawHeaders: $structure['raw_headers'] ?? [],
            );
        }

        $spreadsheet = $this->loadSpreadsheet($filePath);
        try {
            $sheet = $spreadsheet->getActiveSheet();
            $detection = $this->spreadsheetHandler->findHeaderAndMapping($sheet);
            $mapping = $detection['mapping'];
            $headerRow = (int) $detection['header_row'];
            $rawHeaders = $this->readRow($sheet, $headerRow);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return new ImportStructureResult(
            formatSlug: $this->slug(),
            headerRow: $headerRow,
            columnMapping: $mapping,
            detectedColumns: array_flip($mapping),
            rawHeaders: $rawHeaders,
            sampleRows: $this->spreadsheetParser->getRawSampleRows($filePath, [
                'header_row' => $headerRow,
                'column_mapping' => $mapping,
            ], 5),
            headerCandidates: [[
                'row_index' => $headerRow,
                'score' => ($detection['header_confirmed'] ?? false) ? 100 : 0,
                'headers' => $rawHeaders,
                'mapping' => $mapping,
            ]],
            warnings: ($detection['header_confirmed'] ?? false) ? [] : [trans_message('estimate.import_header_not_found')],
            metadata: ['header_confirmed' => $detection['header_confirmed'] ?? false, 'layout' => $detection['layout'] ?? 'legacy_421'],
        );
    }

    public function preview(ImportSession $session, string $filePath, ImportStructureResult $structure): ImportPreviewResult
    {
        if (!$this->structureConfirmed($session, $filePath, $structure)) {
            return new ImportPreviewResult(
                formatSlug: $this->slug(),
                validation: ['errors' => [trans_message('estimate.import_grand_structure_unconfirmed')], 'warnings' => []],
                metadata: ['header_confirmed' => false],
            );
        }
        $sections = [];
        $items = [];
        $totalAmount = 0.0;
        $policy = new ImportRowPolicy;

        $xml = in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xml', 'gsfx'], true);
        $rows = $xml ? $this->xmlParser->getStream($filePath, $structure->toArray()) : $this->parseRows($filePath, $structure);
        foreach ($rows as $row) {
            $dto = $row instanceof EstimateImportRowDTO ? $row : EstimateImportRowDTO::fromArray((array) $row);
            if (! $policy->shouldImport($dto)) {
                continue;
            }
            $payload = $dto->toArray();

            if (($payload['is_section'] ?? false) === true) {
                $sections[] = $payload;

                continue;
            }

            $items[] = $payload;
            if (! $dto->isSubItem && ! $policy->isInformative($dto)
                && (!isset($structure->columnMapping['base_total_price']) || $dto->currentTotalAmount !== null)) {
                $totalAmount += $policy->totalAmount($dto);
            }
        }

        $footer = $xml ? [] : $this->getFooterData();
        $totalAmount = $this->reconciledAmount($totalAmount, $xml ? [] : $rows, $footer);
        $errors = $this->rowValidationErrors($footer, $totalAmount, $xml);
        $validation = $this->validate($session, new ImportPreviewResult($this->slug(), $sections, $items))->toArray();
        $validation['errors'] = array_merge($validation['errors'] ?? [], $errors);
        $validation['is_valid'] = $validation['errors'] === [];

        return new ImportPreviewResult(
            formatSlug: $this->slug(),
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => round($totalAmount, 2),
                'items_count' => count($items),
                'sections_count' => count($sections),
            ],
            validation: $validation,
            summary: [
                'rows_count' => count($items) + count($sections),
            ],
            metadata: [
                'handler' => $this->slug(),
                'header_row' => $structure->headerRow,
                'footer' => $footer,
                'source_errors' => $xml ? [] : $this->spreadsheetParser->getValidationErrors(),
            ],
        );
    }

    public function validate(ImportSession $session, ImportPreviewResult $preview): ImportValidationResult
    {
        $errors = $preview->validation['errors'] ?? [];
        $warnings = $preview->validation['warnings'] ?? [];

        if (($preview->metadata['header_confirmed'] ?? null) === false) {
            $errors[] = trans_message('estimate.import_grand_structure_unconfirmed');
        } elseif ($preview->sections === [] && $preview->items === []) {
            $errors[] = trans_message('estimate.import_empty_preview');
        }

        return new ImportValidationResult(
            errors: array_values(array_unique($errors, SORT_REGULAR)),
            warnings: $warnings,
            summary: [
                'items_count' => count($preview->items),
                'sections_count' => count($preview->sections),
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        if (!$this->structureConfirmed($session, $filePath, $structure)) {
            throw new \InvalidArgumentException('import_grand_structure_unconfirmed');
        }
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xml', 'gsfx'], true)) {
            yield from $this->xmlParser->getStream($filePath, $structure->toArray());

            return;
        }

        $rows = $this->parseRows($filePath, $structure);
        $policy = new ImportRowPolicy;
        $amount = 0.0;
        foreach ($rows as $row) {
            if (!$row->isSection && !$row->isSubItem && !$policy->isInformative($row) && $policy->shouldImport($row)
                && (!isset($structure->columnMapping['base_total_price']) || $row->currentTotalAmount !== null)) {
                $amount += $policy->totalAmount($row);
            }
        }
        $footer = $this->getFooterData();
        $errors = $this->rowValidationErrors($footer, $this->reconciledAmount($amount, $rows, $footer), false);
        if ($errors !== []) {
            throw new \InvalidArgumentException($errors[0]);
        }

        yield from $rows;
    }

    private function parseRows(string $filePath, ImportStructureResult $structure): array
    {
        return iterator_to_array($this->spreadsheetParser->getStream($filePath, [
            'header_row' => $structure->headerRow,
            'column_mapping' => $structure->columnMapping,
            'layout' => $structure->metadata['layout'] ?? null,
        ]), false);
    }

    private function reconciledAmount(float $amount, array $rows, array $footer): float
    {
        $policy = new ImportRowPolicy;
        if (!isset($footer['total_estimate_cost'])) {
            return $amount;
        }
        foreach ($rows as $row) {
            if (($row->rawData['financial_structure']['layout'] ?? null) === 'grand_modern_2020') {
                return $amount;
            }
        }
        foreach (['overhead_cost' => 'overheadAmount', 'profit_cost' => 'profitAmount'] as $field => $property) {
            if (!array_key_exists($field, $footer)) {
                continue;
            }
            $allocated = 0.0;
            foreach ($rows as $row) {
                if (!$row->isSection && !$row->isSubItem && !$policy->isInformative($row)) {
                    $allocated += $row->{$property} ?? 0;
                }
            }
            $declared = (float) $footer[$field];
            if ($allocated === 0.0 || ($declared * $allocated >= 0 && abs($declared) >= abs($allocated))) {
                $amount += $declared - $allocated;
            }
        }

        return $amount;
    }

    private function rowValidationErrors(array $footer, float $amount, bool $xml): array
    {
        $errors = [];
        if (!$xml) {
            foreach ($this->spreadsheetParser->getValidationErrors() as $error) {
                $errors[] = trans_message($error['key'], $error['params']);
            }
        }
        if (isset($footer['total_estimate_cost']) && abs(round($amount, 2) - (float) $footer['total_estimate_cost']) > 0.02) {
            $errors[] = trans_message('estimate.import_grand_total_mismatch', ['computed' => round($amount, 2), 'source' => $footer['total_estimate_cost']]);
        }
        if (isset($footer['total_estimate_cost'], $footer['vat_amount'], $footer['total_with_vat'])
            && abs(round((float) $footer['total_estimate_cost'] + (float) $footer['vat_amount'], 2) - (float) $footer['total_with_vat']) > 0.02) {
            $errors[] = trans_message('estimate.import_grand_vat_mismatch', ['net' => $footer['total_estimate_cost'], 'vat' => $footer['vat_amount'], 'gross' => $footer['total_with_vat']]);
        }
        if (isset($footer['total_estimate_cost'], $footer['vat_amount'], $footer['vat_rate'])
            && abs(round((float) $footer['total_estimate_cost'] * (float) $footer['vat_rate'] / 100, 2) - (float) $footer['vat_amount']) > 0.02) {
            $errors[] = trans_message('estimate.import_grand_vat_mismatch', ['net' => $footer['total_estimate_cost'], 'vat' => $footer['vat_amount'], 'gross' => $footer['total_with_vat'] ?? '']);
        }

        return array_values(array_unique($errors));
    }

    public function getFooterData(): array
    {
        return $this->spreadsheetParser->getFooterData();
    }

    public function detectStructureFromHeaderRow(ImportSession $session, string $filePath, int $headerRow): ImportStructureResult
    {
        $structure = $this->detectStructure($session, $filePath);
        if (in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xml', 'gsfx'], true)) {
            return $structure;
        }
        $spreadsheet = $this->loadSpreadsheet($filePath);
        try {
            $headers = $this->readRow($spreadsheet->getActiveSheet(), $headerRow);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        $confirmed = $structure->headerRow === $headerRow && ($structure->metadata['header_confirmed'] ?? false);
        $mapping = $confirmed ? $structure->columnMapping : (new SpreadsheetHeaderDetector)->mapHeaders($headers);

        return new ImportStructureResult(
            formatSlug: $this->slug(),
            headerRow: $headerRow,
            columnMapping: $mapping,
            detectedColumns: ImportStructureResult::detectedColumnsFromMapping($mapping),
            rawHeaders: $headers,
            sampleRows: $this->spreadsheetParser->getRawSampleRows($filePath, ['header_row' => $headerRow, 'column_mapping' => $mapping], 5),
            warnings: $confirmed ? [] : [trans_message('estimate.import_grand_structure_unconfirmed')],
            metadata: ['header_confirmed' => $confirmed, 'layout' => $structure->metadata['layout'] ?? 'legacy_421'],
        );
    }

    private function structureConfirmed(ImportSession $session, string $filePath, ImportStructureResult $structure): bool
    {
        if (in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xml', 'gsfx'], true)) {
            return true;
        }
        if (($structure->metadata['header_confirmed'] ?? false) === true) {
            return true;
        }

        return ($session->options['manual_structure_confirmed'] ?? false)
            && $structure->headerRow !== null && $structure->headerRow > 0
            && isset($structure->columnMapping['name'])
            && (isset($structure->columnMapping['quantity']) || isset($structure->columnMapping['total_price']) || isset($structure->columnMapping['unit_price']));
    }

    private function loadSpreadsheet(string $filePath): Spreadsheet
    {
        return GrandSmetaSpreadsheetLoader::load($filePath);
    }

    /**
     * @return array<int, mixed>
     */
    private function readRow(Worksheet $sheet, int $rowIndex): array
    {
        $row = $sheet->rangeToArray(
            sprintf('A%d:%s%d', $rowIndex, $sheet->getHighestColumn($rowIndex), $rowIndex),
            null,
            true,
            false
        );

        return $row[0] ?? [];
    }
}
