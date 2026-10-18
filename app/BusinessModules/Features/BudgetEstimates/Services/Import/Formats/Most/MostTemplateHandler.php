<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Most;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Spreadsheet\SpreadsheetSampleLoader;
use App\Models\ImportSession;
use Generator;

final class MostTemplateHandler extends CustomExcelHandler
{
    private const PREVIOUS_TEMPLATE_MARKER_PREFIX = 'PRO'.'HELPER_TEMPLATE';
    private const EXPORT_MARKER = 'MOST_ESTIMATE_EXPORT_V1';

    public function slug(): string
    {
        return 'most_template';
    }

    public function label(): string
    {
        return 'Шаблон МОСТ';
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $spreadsheet = (new SpreadsheetSampleLoader)->load($filePath, 0);
        try {
            $description = (string) $spreadsheet->getProperties()->getDescription();
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        if ($description === self::EXPORT_MARKER || str_contains($description, 'MOST_TEMPLATE') || str_contains($description, self::PREVIOUS_TEMPLATE_MARKER_PREFIX)) {
            return new ImportDetectionResult(
                detectedType: 'most_template',
                formatSlug: $this->slug(),
                label: $this->label(),
                confidence: 1.0,
                indicators: ['document_property_most_template'],
            );
        }

        $result = parent::detect($session, $filePath);

        return new ImportDetectionResult(
            detectedType: 'most_template',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: min(0.4, $result->confidence),
            requiresConfirmation: true,
            indicators: $result->indicators,
            warnings: [trans_message('estimate.import_low_confidence')],
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        return $this->markExportStructure(parent::detectStructure($session, $filePath), $filePath);
    }

    public function detectStructureFromHeaderRow(ImportSession $session, string $filePath, int $headerRow): ImportStructureResult
    {
        return $this->markExportStructure(parent::detectStructureFromHeaderRow($session, $filePath, $headerRow), $filePath);
    }

    protected function rowsToDtos(array $rows, ImportStructureResult $structure): Generator
    {
        if ($structure->metadata['most_export'] ?? false) {
            foreach ($rows as $rowNumber => $row) {
                if (($row[0] ?? null) === 'ПОДПИСИ ОТВЕТСТВЕННЫХ ЛИЦ') {
                    unset($rows[$rowNumber]);
                }
            }
        }

        yield from parent::rowsToDtos($rows, $structure);
    }

    private function markExportStructure(ImportStructureResult $structure, string $filePath): ImportStructureResult
    {
        $spreadsheet = (new SpreadsheetSampleLoader)->load($filePath, 0);
        try {
            $isExport = $spreadsheet->getProperties()->getDescription() === self::EXPORT_MARKER;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        if (! $isExport) {
            return $structure;
        }

        return new ImportStructureResult(
            formatSlug: $structure->formatSlug,
            headerRow: $structure->headerRow,
            columnMapping: $structure->columnMapping,
            detectedColumns: $structure->detectedColumns,
            rawHeaders: $structure->rawHeaders,
            sampleRows: $structure->sampleRows,
            headerCandidates: $structure->headerCandidates,
            rowStyles: $structure->rowStyles,
            warnings: $structure->warnings,
            metadata: array_merge($structure->metadata, ['most_export' => true]),
            aiMappingApplied: $structure->aiMappingApplied,
        );
    }
}
