<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\Import;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\ImportSummaryDTO;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTOs\KsrResourceDTO;
use RuntimeException;
use Throwable;

final class KsrCsvParser
{
    private const DELIMITERS = [";", ",", "\t"];

    public function parse(string $filePath): iterable
    {
        [$encoding, $delimiter, $headerRow, $mapping] = $this->detectStructure($filePath);
        $handle = $this->openFile($filePath);
        $rowNumber = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $rowNumber++;

                if ($rowNumber <= $headerRow) {
                    continue;
                }

                $row = $this->parseLine($line, $encoding, $delimiter);
                $dto = $this->mapRow($row, $mapping, $rowNumber);

                if ($dto !== null) {
                    yield $dto;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    public function parseFile(string $filePath, callable $callback): ImportSummaryDTO
    {
        $processed = 0;
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($this->parse($filePath) as $resource) {
            $processed++;

            try {
                $result = $callback($resource);

                if ($result === false) {
                    $skipped++;
                    continue;
                }

                $imported++;
            } catch (Throwable $exception) {
                $failed++;
                $errors[] = [
                    'row' => $processed,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return new ImportSummaryDTO(
            processed: $processed,
            imported: $imported,
            skipped: $skipped,
            failed: $failed,
            errors: $errors,
            metadata: [
                'file_name' => basename($filePath),
            ],
        );
    }

    private function detectStructure(string $filePath): array
    {
        $encoding = $this->detectEncoding($filePath);
        $sampleRows = $this->readSampleRows($filePath, $encoding);
        $delimiter = $this->detectDelimiter($sampleRows);
        $headerIndex = $this->detectHeaderRow($sampleRows, $delimiter);
        $headers = $sampleRows[$headerIndex] ?? [];
        $mapping = $this->mapHeaders(str_getcsv($headers, $delimiter));

        if ($mapping === []) {
            $mapping = [
                'okpd2_code' => 0,
                'code' => 1,
                'name' => 2,
                'unit' => 3,
            ];
        }

        return [$encoding, $delimiter, $headerIndex + 1, $mapping];
    }

    private function readSampleRows(string $filePath, string $encoding): array
    {
        $handle = $this->openFile($filePath);
        $rows = [];

        try {
            for ($i = 0; $i < 30 && ($line = fgets($handle)) !== false; $i++) {
                $rows[] = $this->normalizeEncoding($line, $encoding);
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function detectEncoding(string $filePath): string
    {
        $handle = $this->openFile($filePath);
        $sample = '';

        try {
            for ($i = 0; $i < 20 && ($line = fgets($handle)) !== false; $i++) {
                $sample .= $line;
            }
        } finally {
            fclose($handle);
        }

        return mb_check_encoding($sample, 'UTF-8') ? 'UTF-8' : 'Windows-1251';
    }

    private function detectDelimiter(array $sampleRows): string
    {
        $scores = array_fill_keys(self::DELIMITERS, 0);

        foreach ($sampleRows as $line) {
            foreach (self::DELIMITERS as $delimiter) {
                $columns = str_getcsv($line, $delimiter);
                $filled = count(array_filter($columns, static fn (?string $value): bool => trim((string) $value) !== ''));
                $scores[$delimiter] += $filled > 1 ? $filled : 0;
            }
        }

        arsort($scores);

        return (string) array_key_first($scores);
    }

    private function detectHeaderRow(array $sampleRows, string $delimiter): int
    {
        $bestIndex = 0;
        $bestScore = -1;

        foreach ($sampleRows as $index => $line) {
            $columns = str_getcsv($line, $delimiter);
            $text = $this->normalizeToken(implode(' ', $columns));
            $score = 0;

            foreach (['код', 'шифр', 'наименование', 'название', 'единица', 'едизм', 'изм', 'группа', 'тип'] as $keyword) {
                if (str_contains($text, $keyword)) {
                    $score += 5;
                }
            }

            $score += min(count(array_filter($columns, static fn (?string $value): bool => trim((string) $value) !== '')), 5);

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestIndex;
    }

    private function mapHeaders(array $headers): array
    {
        $mapping = [];

        foreach ($headers as $index => $header) {
            $token = $this->normalizeToken((string) $header);

            if ($this->matchesAny($token, ['окпд'])) {
                $mapping['okpd2_code'] ??= $index;
            } elseif ($this->matchesAny($token, ['код', 'шифр', 'обоснование'])) {
                $mapping['code'] ??= $index;
            } elseif ($this->matchesAny($token, ['наименование', 'название', 'ресурс'])) {
                $mapping['name'] ??= $index;
            } elseif ($this->matchesAny($token, ['единица', 'едизм', 'изм', 'ед'])) {
                $mapping['unit'] ??= $index;
            } elseif ($this->matchesAny($token, ['тип', 'вид'])) {
                $mapping['resource_type'] ??= $index;
            } elseif ($this->matchesAny($token, ['группа', 'раздел'])) {
                $mapping['group'] ??= $index;
            }
        }

        return isset($mapping['code'], $mapping['name']) ? $mapping : [];
    }

    private function mapRow(array $row, array $mapping, int $rowNumber): ?KsrResourceDTO
    {
        $code = $this->cell($row, $mapping['code'] ?? null);
        $name = $this->cell($row, $mapping['name'] ?? null);

        if ($code === null || $name === null) {
            return null;
        }

        return new KsrResourceDTO(
            code: $code,
            name: $name,
            unit: $this->cell($row, $mapping['unit'] ?? null),
            resourceType: $this->cell($row, $mapping['resource_type'] ?? null),
            group: $this->cell($row, $mapping['group'] ?? null),
            rawData: [
                'row_number' => $rowNumber,
                'okpd2_code' => $this->cell($row, $mapping['okpd2_code'] ?? null),
                'row' => $row,
            ],
        );
    }

    private function parseLine(string $line, string $encoding, string $delimiter): array
    {
        return array_map(
            static fn (?string $value): string => trim((string) $value),
            str_getcsv($this->normalizeEncoding($line, $encoding), $delimiter)
        );
    }

    private function normalizeEncoding(string $value, string $encoding): string
    {
        $value = $encoding === 'UTF-8' ? $value : mb_convert_encoding($value, 'UTF-8', $encoding);

        return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
    }

    private function cell(array $row, ?int $index): ?string
    {
        if ($index === null || !array_key_exists($index, $row)) {
            return null;
        }

        $value = trim((string) $row[$index]);

        return $value === '' ? null : $value;
    }

    private function normalizeToken(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return preg_replace('/[^a-zа-яё0-9]+/u', '', $value) ?? $value;
    }

    private function matchesAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function openFile(string $filePath)
    {
        $handle = @fopen($filePath, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open file: ' . $filePath);
        }

        return $handle;
    }
}
