<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import\Pdf;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;

final readonly class PdfEstimateTableQualityAnalyzer
{
    /**
     * @param array<int, EstimateImportRowDTO> $rows
     * @return array<string, mixed>
     */
    public function assessRows(array $rows, float $minRequiredScore): array
    {
        $items = array_values(array_filter(
            $rows,
            static fn (EstimateImportRowDTO $row): bool => !$row->isSection
        ));

        return $this->assessItems(
            array_map(static fn (EstimateImportRowDTO $row): array => $row->toArray(), $items),
            $minRequiredScore
        );
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function assessItems(array $items, float $minRequiredScore): array
    {
        $scores = [];
        $suspiciousRows = 0;

        foreach ($items as $item) {
            $score = $this->rowQualityScore($item);
            $scores[] = $score;

            if ($score < 0.5) {
                $suspiciousRows++;
            }
        }

        if ($scores === []) {
            return [
                'score' => 0.0,
                'items_count' => 0,
                'suspicious_rows_count' => 0,
                'min_required_score' => $minRequiredScore,
            ];
        }

        $averageScore = array_sum($scores) / count($scores);
        $suspiciousRatio = $suspiciousRows / count($scores);
        $score = max(0.0, min(1.0, $averageScore - ($suspiciousRatio > 0.45 ? 0.2 : 0.0)));

        return [
            'score' => round($score, 3),
            'items_count' => count($items),
            'suspicious_rows_count' => $suspiciousRows,
            'suspicious_rows_ratio' => round($suspiciousRatio, 3),
            'min_required_score' => $minRequiredScore,
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function rowQualityScore(array $item): float
    {
        $name = trim((string) ($item['item_name'] ?? ''));
        $unit = mb_strtolower(trim((string) ($item['unit'] ?? '')));
        $quantity = (float) ($item['quantity'] ?? 0);
        $total = (float) (($item['current_total_amount'] ?? null) ?? ($item['total_amount'] ?? 0));
        $hasCode = trim((string) ($item['code'] ?? '')) !== '';
        $sectionNumber = trim((string) ($item['section_number'] ?? ''));
        $score = 0.0;

        if ($name !== '' && preg_match('/\p{L}/u', $name) === 1 && mb_strlen($name) >= 4) {
            $score += 0.25;
        }

        if (!$this->startsWithColumnNumberSequence($name) && !$this->isMostlyNumbers($name)) {
            $score += 0.2;
        }

        if ($this->isKnownEstimateUnit($unit)) {
            $score += 0.22;
        } elseif ($unit !== '' && !$this->isSuspiciousPdfUnit($unit)) {
            $score += 0.1;
        }

        if ($quantity > 0.0 && $total > 0.0) {
            $score += 0.2;
        }

        if ($hasCode) {
            $score += 0.15;
        } elseif (!$this->hasEmbeddedRateCode($name)) {
            $score += 0.1;
        }

        if ($this->startsWithColumnNumberSequence($name) || $this->hasEmbeddedRateCode($name)) {
            $score -= 0.25;
        }

        if ($this->isSuspiciousPdfUnit($unit)) {
            $score -= 0.2;
        }

        if ($this->containsSummaryOrResourceTerms($name)) {
            $score -= 0.45;
        }

        if (in_array($sectionNumber, ['10', '100', '1000'], true) && !$hasCode) {
            $score -= 0.25;
        }

        if ($this->hasHighNumericDensity($name)) {
            $score -= 0.25;
        }

        return max(0.0, min(1.0, $score));
    }

    private function startsWithColumnNumberSequence(string $name): bool
    {
        return preg_match('/^(?:\d+\s+){3,}\d+/u', $name) === 1;
    }

    private function isMostlyNumbers(string $name): bool
    {
        $numericTokens = preg_match_all('/\d+(?:[,.]\d+)?/u', $name);
        $wordTokens = preg_match_all('/\p{L}{2,}/u', $name);

        return is_int($numericTokens)
            && is_int($wordTokens)
            && $numericTokens > max(2, $wordTokens + 1);
    }

    private function hasEmbeddedRateCode(string $name): bool
    {
        return preg_match('/\d{2}-\d{2}-\s*\d{3}-\d+/u', $name) === 1
            || preg_match('/\d{3}-\d{4}\p{L}/u', $name) === 1;
    }

    private function isSuspiciousPdfUnit(string $unit): bool
    {
        return in_array($unit, ['зарплата', 'машин', 'ресурсы', 'итого', 'машинистов'], true)
            || preg_match('/\d|\(|\)/u', $unit) === 1;
    }

    private function isKnownEstimateUnit(string $unit): bool
    {
        return preg_match(
            '/^(?:м|м2|м²|м3|м³|кг|т|шт|компл|пог\.?\s?м|чел-ч|маш-ч|смета|раз|sht|pcs|m2|m3|kg)$/u',
            $unit
        ) === 1;
    }

    private function containsSummaryOrResourceTerms(string $name): bool
    {
        return preg_match(
            '/\b(итого|всего|сметная прибыль|накладные расходы|зарплата|эксплуатация машин|материальные|материальные ресурсы|оборудование|ресурсы)\b/ui',
            $name
        ) === 1;
    }

    private function hasHighNumericDensity(string $name): bool
    {
        $numericTokens = preg_match_all('/\d+(?:[,.]\d+)?/u', $name);
        $wordTokens = preg_match_all('/\p{L}{2,}/u', $name);

        return is_int($numericTokens)
            && is_int($wordTokens)
            && $numericTokens >= 4
            && $numericTokens >= ($wordTokens * 2);
    }
}
