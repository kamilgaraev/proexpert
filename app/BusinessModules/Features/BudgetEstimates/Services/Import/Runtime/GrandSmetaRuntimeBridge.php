<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\EstimateImportFinancialSettingsResolver;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\GrandSmeta\GrandSmetaSpreadsheetLoader;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\ImportRowPolicy;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Parsers\GrandSmetaXMLParser;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetSampleLoader;
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

        $content = (new SpreadsheetSampleLoader)->load($filePath, 15);
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
                'score' => 100,
                'headers' => $rawHeaders,
                'mapping' => $mapping,
            ]],
        );
    }

    public function preview(ImportSession $session, string $filePath, ImportStructureResult $structure): ImportPreviewResult
    {
        $sections = [];
        $items = [];
        $totalAmount = 0.0;
        $policy = new ImportRowPolicy;

        foreach ($this->streamRows($session, $filePath, $structure) as $row) {
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
            if (! $dto->isSubItem && ! $policy->isInformative($dto)) {
                $totalAmount += $policy->totalAmount($dto);
            }
        }

        $footer = in_array(strtolower(pathinfo($filePath, PATHINFO_EXTENSION)), ['xml', 'gsfx'], true)
            ? [] : $this->getFooterData();
        $importedTotals = (new EstimateImportFinancialSettingsResolver)->resolveImportedTotals($footer);

        return new ImportPreviewResult(
            formatSlug: $this->slug(),
            sections: $sections,
            items: $items,
            totals: [
                'total_amount' => $importedTotals['total_amount'] ?? round($totalAmount, 2),
                'items_count' => count($items),
                'sections_count' => count($sections),
            ],
            validation: $this->validate($session, new ImportPreviewResult($this->slug(), $sections, $items))->toArray(),
            summary: [
                'rows_count' => count($items) + count($sections),
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

        if ($preview->sections === [] && $preview->items === []) {
            $errors[] = trans_message('estimate.import_empty_preview');
        }

        return new ImportValidationResult(
            errors: $errors,
            summary: [
                'items_count' => count($preview->items),
                'sections_count' => count($preview->sections),
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['xml', 'gsfx'], true)) {
            yield from $this->xmlParser->getStream($filePath, $structure->toArray());

            return;
        }

        yield from $this->spreadsheetParser->getStream($filePath, [
            'header_row' => $structure->headerRow,
            'column_mapping' => $structure->columnMapping,
        ]);
    }

    public function getFooterData(): array
    {
        return $this->spreadsheetParser->getFooterData();
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
