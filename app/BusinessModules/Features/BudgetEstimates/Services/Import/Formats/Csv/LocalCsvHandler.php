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
        $preview = parent::preview($session, $filePath, $structure);

        return new ImportPreviewResult(
            formatSlug: $this->slug(),
            sections: $preview->sections,
            items: $preview->items,
            totals: $preview->totals,
            validation: $preview->validation,
            summary: $preview->summary,
            quality: $preview->quality,
            metadata: $preview->metadata + [
                'delimiter' => $structure->metadata['delimiter'] ?? null,
                'encoding' => $structure->metadata['encoding'] ?? null,
            ],
        );
    }

    public function streamRows(ImportSession $session, string $filePath, ImportStructureResult $structure): Generator
    {
        $this->resetImportState();
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

        try {
            while (($maxRows === null || $rowNumber <= $maxRows)
                && ($record = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
                $rows[$rowNumber] = array_map(
                    static fn (?string $value): string => trim($encoding === 'UTF-8'
                        ? (string) $value
                        : mb_convert_encoding((string) $value, 'UTF-8', $encoding)),
                    $record
                );
                if ($rowNumber === 1) {
                    $rows[$rowNumber][0] = ltrim($rows[$rowNumber][0], "\xEF\xBB\xBF");
                }
                $rowNumber++;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function detectDelimiter(string $filePath): string
    {
        $best = ';';
        $bestScore = 0;
        foreach ([';', ',', "\t"] as $delimiter) {
            $handle = fopen($filePath, 'rb');
            if ($handle === false) {
                return $best;
            }
            $counts = [];
            try {
                for ($index = 0; $index < 10 && ($row = fgetcsv($handle, null, $delimiter, '"', '')) !== false; $index++) {
                    $counts[] = count($row);
                }
            } finally {
                fclose($handle);
            }
            $frequencies = ($counts[0] ?? 0) > 1
                ? array_count_values(array_filter($counts, static fn (int $count): bool => $count > 1))
                : [];
            $score = 0;
            foreach ($frequencies as $width => $frequency) {
                $score = max($score, $frequency * (int) $width);
            }
            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
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
