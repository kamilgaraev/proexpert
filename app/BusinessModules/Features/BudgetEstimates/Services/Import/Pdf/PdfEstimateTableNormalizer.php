<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;

final class PdfEstimateTableNormalizer
{
    /**
     * @return array<int, EstimateImportRowDTO>
     */
    public function normalize(string $text): array
    {
        $rows = [];
        $lines = preg_split('/\R/u', $text) ?: [];

        foreach ($lines as $index => $line) {
            $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^(раздел|глава)\s+(.+)/ui', $line, $sectionMatch) === 1) {
                $rows[] = new EstimateImportRowDTO(
                    rowNumber: $index + 1,
                    itemName: trim($sectionMatch[0]),
                    isSection: true,
                    rawData: [$line],
                );
                continue;
            }

            if (preg_match('/^(\d+(?:\.\d+)?)\s+(.+?)\s+([а-яa-z%.\-]+)\s+(-?\d+(?:[,.]\d+)?)\s+(-?\d+(?:[,.]\d+)?)\s+(-?\d+(?:[,.]\d+)?)/ui', $line, $match) !== 1) {
                continue;
            }

            $rows[] = new EstimateImportRowDTO(
                rowNumber: $index + 1,
                sectionNumber: $match[1],
                itemName: trim($match[2]),
                unit: $match[3],
                quantity: $this->number($match[4]),
                unitPrice: $this->number($match[5]),
                isSection: false,
                rawData: [$line],
                currentTotalAmount: $this->number($match[6]),
            );
        }

        return $rows;
    }

    private function number(string $value): float
    {
        return (float) str_replace(',', '.', $value);
    }
}
