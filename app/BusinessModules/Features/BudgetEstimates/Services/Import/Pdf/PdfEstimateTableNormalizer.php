<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;

final class PdfEstimateTableNormalizer
{
    private const MAX_ROW_LINE_SPAN = 4;

    /**
     * @return array<int, EstimateImportRowDTO>
     */
    public function normalize(string $text): array
    {
        $rows = [];
        $lines = $this->normalizeLines($text);
        $linesCount = count($lines);
        $currentSectionPath = null;

        for ($index = 0; $index < $linesCount; $index++) {
            $line = $lines[$index];
            $sectionMatch = $this->matchExplicitSection($line);
            if ($sectionMatch !== null) {
                $section = $this->sectionDto($sectionMatch, $index + 1, $line);
                $currentSectionPath = $section->sectionPath;
                $rows[] = $section;
                continue;
            }

            if ($this->looksLikeItemStart($line)) {
                $candidate = $line;
                for ($span = 0; $span < self::MAX_ROW_LINE_SPAN && ($index + $span) < $linesCount; $span++) {
                    if ($span > 0) {
                        $nextLine = $lines[$index + $span];
                        if ($this->looksLikeItemStart($nextLine) || $this->matchExplicitSection($nextLine) !== null) {
                            break;
                        }

                        $candidate .= ' ' . $nextLine;
                    }

                    $row = $this->parseItemRow($candidate, $index + 1, $currentSectionPath);
                    if ($row !== null) {
                        $rows[] = $row;
                        $index += $span;
                        continue 2;
                    }
                }
            }

            $sectionMatch = $this->matchNumberedSection($line);
            if ($sectionMatch !== null) {
                $section = $this->sectionDto($sectionMatch, $index + 1, $line);
                $currentSectionPath = $section->sectionPath;
                $rows[] = $section;
                continue;
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $normalized = [];

        foreach ($lines as $line) {
            $line = str_replace(["\xC2\xA0", "\t"], ' ', $line);
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);

            if ($line !== '') {
                $normalized[] = $line;
            }
        }

        return $normalized;
    }

    /**
     * @return array{number: string|null, name: string, path: string|null, level: int}|null
     */
    private function matchExplicitSection(string $line): ?array
    {
        if (preg_match('/^(раздел|глава)\s*(?:№|N|No\.?)?\s*(\d+(?:\.\d+)*)?[.\s:–—-]*(.*)$/ui', $line, $sectionMatch) !== 1) {
            return null;
        }

        $number = trim((string) ($sectionMatch[2] ?? ''));
        $name = trim((string) ($sectionMatch[3] ?? ''));

        return $this->sectionData($number !== '' ? $number : null, $name !== '' ? $name : trim($line));
    }

    /**
     * @return array{number: string|null, name: string, path: string|null, level: int}|null
     */
    private function matchNumberedSection(string $line): ?array
    {
        if (preg_match('/^(\d+(?:\.\d+)*)\s+(.+)$/u', $line, $match) !== 1) {
            return null;
        }

        $name = trim($match[2]);
        if ($name === '' || !$this->hasLetters($name) || $this->startsWithColumnNumberSequence($name)) {
            return null;
        }

        $tokens = preg_split('/\s+/u', $name) ?: [];
        if ($this->startsWithScaleUnitNoise($match[1], $tokens)) {
            return null;
        }

        $firstToken = $tokens[0] ?? '';
        if ($this->looksLikeRateCode($firstToken) || $this->isSummaryOrResourceRow($name)) {
            return null;
        }

        return $this->sectionData($match[1], $name);
    }

    /**
     * @param array{number: string|null, name: string, path: string|null, level: int} $section
     */
    private function sectionDto(array $section, int $rowNumber, string $line): EstimateImportRowDTO
    {
        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: $section['number'],
            itemName: $section['name'],
            isSection: true,
            itemType: 'section',
            level: $section['level'],
            sectionPath: $section['path'],
            rawData: [$line],
        );
    }

    /**
     * @return array{number: string|null, name: string, path: string|null, level: int}
     */
    private function sectionData(?string $number, string $name): array
    {
        $normalizedNumber = $number !== null ? trim($number, ". \t\n\r\0\x0B") : null;
        $sectionName = trim($name, ". \t\n\r\0\x0B");

        return [
            'number' => $normalizedNumber,
            'name' => $sectionName !== '' ? $sectionName : ($normalizedNumber ?? ''),
            'path' => $normalizedNumber,
            'level' => $normalizedNumber !== null ? substr_count($normalizedNumber, '.') : 0,
        ];
    }

    private function looksLikeItemStart(string $line): bool
    {
        return preg_match('/^\d+(?:\.\d+)*\s+\S+/u', $line) === 1;
    }

    private function parseItemRow(string $line, int $rowNumber, ?string $currentSectionPath): ?EstimateImportRowDTO
    {
        if (preg_match('/^(\d+(?:\.\d+)*)\s+(.+)$/u', $line, $match) !== 1) {
            return null;
        }

        $sectionNumber = $match[1];
        $tokens = preg_split('/\s+/u', trim($match[2])) ?: [];
        if (count($tokens) < 5) {
            return null;
        }

        if ($this->startsWithScaleUnitNoise($sectionNumber, $tokens)) {
            return null;
        }

        $tailCells = [];
        $cursor = count($tokens) - 1;
        while ($cursor >= 0 && $this->isTailCell($tokens[$cursor])) {
            array_unshift($tailCells, $tokens[$cursor]);
            $cursor--;
        }

        $numericTail = $this->numericValuesFromTailCells($tailCells);
        if (count($numericTail) < 2 || $cursor < 1) {
            return null;
        }

        $unitTokens = [$tokens[$cursor]];
        $cursor--;

        if ($cursor >= 0 && $this->isUnitScaleToken($tokens[$cursor])) {
            array_unshift($unitTokens, $tokens[$cursor]);
            $cursor--;
        }

        $nameTokens = array_slice($tokens, 0, $cursor + 1);
        if ($nameTokens === []) {
            return null;
        }

        $code = null;
        if ($this->looksLikeRateCode($nameTokens[0])) {
            $code = array_shift($nameTokens);
        }

        $itemName = trim(implode(' ', $nameTokens));
        if ($itemName === '' || !$this->hasLetters($itemName)) {
            return null;
        }

        $unit = implode(' ', $unitTokens);
        if ($this->isSummaryOrResourceRow($itemName) || $this->isSummaryOrResourceRow($unit)) {
            return null;
        }

        $quantity = $numericTail[0];
        $total = $numericTail[count($numericTail) - 1];
        $unitPrice = count($numericTail) >= 3
            ? $numericTail[1]
            : ($quantity !== 0.0 ? round($total / $quantity, 6) : 0.0);

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: $sectionNumber,
            itemName: $itemName,
            unit: $unit,
            quantity: $quantity,
            unitPrice: $unitPrice,
            code: $code,
            isSection: false,
            sectionPath: $currentSectionPath,
            rawData: [$line],
            currentTotalAmount: $total,
        );
    }

    private function isTailCell(string $token): bool
    {
        return $this->isNumberToken($token) || in_array($token, ['-', '–', '—'], true);
    }

    private function isNumberToken(string $token): bool
    {
        return preg_match('/^-?\d+(?:[,.]\d+)?$/u', $token) === 1;
    }

    /**
     * @param array<int, string> $tailCells
     * @return array<int, float>
     */
    private function numericValuesFromTailCells(array $tailCells): array
    {
        $tokens = array_values(array_filter($tailCells, fn (string $token): bool => $this->isNumberToken($token)));
        if (count($tokens) < 2) {
            return [];
        }

        $values = [];
        $index = 0;
        while ($index < count($tokens)) {
            $value = $this->readNumericValue($tokens, $index, $index > 0);
            $values[] = $value['value'];
            $index = $value['next_index'];
        }

        return $values;
    }

    /**
     * @param array<int, string> $tokens
     * @return array{value: float, next_index: int}
     */
    private function readNumericValue(array $tokens, int $index, bool $allowGrouped): array
    {
        $token = $tokens[$index];
        if (!$allowGrouped || preg_match('/^-?\d{1,3}$/u', $token) !== 1) {
            return ['value' => $this->number($token), 'next_index' => $index + 1];
        }

        $group = [$token];
        $cursor = $index + 1;
        $hasDecimalGroup = false;
        while (
            $cursor < count($tokens)
            && preg_match('/^\d{3}(?:[,.]\d+)?$/u', $tokens[$cursor]) === 1
        ) {
            $group[] = $tokens[$cursor];
            $hasDecimalGroup = str_contains($tokens[$cursor], ',') || str_contains($tokens[$cursor], '.');
            $cursor++;

            if ($hasDecimalGroup) {
                break;
            }
        }

        if (count($group) > 1 && ($hasDecimalGroup || count($group) > 2)) {
            return ['value' => $this->number(implode('', $group)), 'next_index' => $cursor];
        }

        return ['value' => $this->number($token), 'next_index' => $index + 1];
    }

    private function isUnitScaleToken(string $token): bool
    {
        return in_array($token, ['10', '100', '1000'], true);
    }

    private function startsWithScaleUnitNoise(string $sectionNumber, array $tokens): bool
    {
        if (!in_array($sectionNumber, ['10', '100', '1000'], true)) {
            return false;
        }

        $nextToken = mb_strtolower((string) ($tokens[0] ?? ''));

        return preg_match('/^(?:м|м2|м²|м3|м³|кг|т|шт|чел|маш|руб|%)/u', $nextToken) === 1;
    }

    private function isSummaryOrResourceRow(string $itemName): bool
    {
        return preg_match(
            '/\b(итого|всего|сметная прибыль|накладные расходы|зарплата|эксплуатация машин|материальные|материальные ресурсы|оборудование|ресурсы)\b/ui',
            $itemName
        ) === 1;
    }

    private function looksLikeRateCode(string $token): bool
    {
        return preg_match('/^(?=.*\d)(?=.*[-.\/])[\p{L}\d().\/-]+$/u', $token) === 1;
    }

    private function hasLetters(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    private function startsWithColumnNumberSequence(string $value): bool
    {
        return preg_match('/^(?:\d+\s+){3,}\d+/u', $value) === 1;
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
