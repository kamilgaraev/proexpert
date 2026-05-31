<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Csv;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Formats\Excel\CustomExcelHandler;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportDetectionResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportPreviewResult;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Runtime\ImportStructureResult;
use App\Models\ImportSession;
use Generator;

final class LocalCsvHandler extends CustomExcelHandler
{
    public function slug(): string
    {
        return 'local_csv';
    }

    public function label(): string
    {
        return 'CSV/TXT смета';
    }

    public function supportedExtensions(): array
    {
        return ['csv', 'txt'];
    }

    public function detect(ImportSession $session, string $filePath): ImportDetectionResult
    {
        $rows = $this->readCsvRows($filePath, 60);
        $detection = $this->headerDetector->detect($rows);
        $mapping = $detection['column_mapping'] ?? [];
        $confidence = $this->confidenceFromMapping($mapping, (int) ($detection['score'] ?? 0));

        return new ImportDetectionResult(
            detectedType: 'custom',
            formatSlug: $this->slug(),
            label: $this->label(),
            confidence: $confidence,
            requiresConfirmation: true,
            indicators: array_merge(['delimited_text'], $this->indicatorsFromMapping($mapping)),
            warnings: $confidence < 0.5 ? [trans_message('estimate.import_low_confidence')] : [],
        );
    }

    public function detectStructure(ImportSession $session, string $filePath): ImportStructureResult
    {
        $rows = $this->readCsvRows($filePath, 80);

        return $this->withCsvMetadata($this->structureFromRows($rows), $filePath);
    }

    public function detectStructureFromHeaderRow(ImportSession $session, string $filePath, int $headerRow): ImportStructureResult
    {
        $rows = $this->readCsvRows($filePath, max(80, $headerRow + 20));

        return $this->withCsvMetadata($this->structureFromRows($rows, $headerRow), $filePath);
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
                'delimiter' => $structure->metadata['delimiter'] ?? null,
                'encoding' => $structure->metadata['encoding'] ?? null,
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        yield from $this->rowsToDtos($this->readCsvRows($filePath), $structure);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readCsvRows(string $filePath, ?int $maxRows = null): array
    {
        $delimiter = $this->detectDelimiter($filePath);
        $encoding = $this->detectEncoding($filePath);
        $handle = fopen($filePath, 'rb');
        $rows = [];
        $rowNumber = 1;

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            if ($maxRows !== null && $rowNumber > $maxRows) {
                break;
            }

            if ($encoding !== 'UTF-8') {
                $line = mb_convert_encoding($line, 'UTF-8', $encoding);
            }

            $rows[$rowNumber] = array_map(
                static fn (string $value): string => trim($value),
                str_getcsv($line, $delimiter)
            );
            $rowNumber++;
        }

        fclose($handle);

        return $rows;
    }

    private function detectDelimiter(string $filePath): string
    {
        $sample = file_get_contents($filePath, false, null, 0, 4096) ?: '';
        $delimiters = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($delimiters);

        return (string) array_key_first($delimiters);
    }

    private function detectEncoding(string $filePath): string
    {
        $sample = file_get_contents($filePath, false, null, 0, 4096) ?: '';
        $encoding = mb_detect_encoding($sample, ['UTF-8', 'Windows-1251', 'CP1251'], true);

        return $encoding ?: 'UTF-8';
    }

    private function withCsvMetadata(ImportStructureResult $structure, string $filePath): ImportStructureResult
    {
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
            metadata: $structure->metadata + [
                'delimiter' => $this->detectDelimiter($filePath),
                'encoding' => $this->detectEncoding($filePath),
            ],
            aiMappingApplied: $structure->aiMappingApplied,
        );
    }
}
