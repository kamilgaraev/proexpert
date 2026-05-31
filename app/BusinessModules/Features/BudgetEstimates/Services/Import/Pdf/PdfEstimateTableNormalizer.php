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

        for ($index = 0; $index < $linesCount; $index++) {
            $line = $lines[$index];
            $sectionMatch = $this->matchSection($line);
            if ($sectionMatch !== null) {
                $rows[] = new EstimateImportRowDTO(
                    rowNumber: $index + 1,
                    itemName: $sectionMatch,
                    isSection: true,
                    rawData: [$line],
                );
                continue;
            }

            if (!$this->looksLikeItemStart($line)) {
                continue;
            }

            $candidate = $line;
            for ($span = 0; $span < self::MAX_ROW_LINE_SPAN && ($index + $span) < $linesCount; $span++) {
                if ($span > 0) {
                    $nextLine = $lines[$index + $span];
                    if ($this->looksLikeItemStart($nextLine) || $this->matchSection($nextLine) !== null) {
                        break;
                    }

                    $candidate .= ' ' . $nextLine;
                }

                $row = $this->parseItemRow($candidate, $index + 1);
                if ($row !== null) {
                    $rows[] = $row;
                    $index += $span;
                    continue 2;
                }
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

    private function matchSection(string $line): ?string
    {
        if (preg_match('/^(раздел|глава)\s+(.+)/ui', $line, $sectionMatch) !== 1) {
            return null;
        }

        return trim($sectionMatch[0]);
    }

    private function looksLikeItemStart(string $line): bool
    {
        return preg_match('/^\d+(?:\.\d+)*\s+\S+/u', $line) === 1;
    }

    private function parseItemRow(string $line, int $rowNumber): ?EstimateImportRowDTO
    {
        if (preg_match('/^(\d+(?:\.\d+)*)\s+(.+)$/u', $line, $match) !== 1) {
            return null;
        }

        $sectionNumber = $match[1];
        $tokens = preg_split('/\s+/u', trim($match[2])) ?: [];
        if (count($tokens) < 5) {
            return null;
        }

        $tailCells = [];
        $cursor = count($tokens) - 1;
        while ($cursor >= 0 && $this->isTailCell($tokens[$cursor])) {
            array_unshift($tailCells, $tokens[$cursor]);
            $cursor--;
        }

        $numericTail = array_values(array_filter($tailCells, fn (string $token): bool => $this->isNumberToken($token)));
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

        $quantity = $this->number($numericTail[0]);
        $total = $this->number($numericTail[count($numericTail) - 1]);
        $unitPrice = count($numericTail) >= 3
            ? $this->number($numericTail[1])
            : ($quantity !== 0.0 ? round($total / $quantity, 6) : 0.0);

        return new EstimateImportRowDTO(
            rowNumber: $rowNumber,
            sectionNumber: $sectionNumber,
            itemName: $itemName,
            unit: implode(' ', $unitTokens),
            quantity: $quantity,
            unitPrice: $unitPrice,
            code: $code,
            isSection: false,
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

    private function isUnitScaleToken(string $token): bool
    {
        return in_array($token, ['10', '100', '1000'], true);
    }

    private function looksLikeRateCode(string $token): bool
    {
        return preg_match('/^(?=.*\d)(?=.*[-.\/])[\p{L}\d().\/-]+$/u', $token) === 1;
    }

    private function hasLetters(string $value): bool
    {
        return preg_match('/\p{L}/u', $value) === 1;
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
