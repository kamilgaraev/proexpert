<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use Illuminate\Support\Facades\Log;

class FormulaAwarenessService
{
    private const TOLERANCE = 0.05;

    public function validate(array $rowData): array
    {
        $warnings = [];

        $qty        = (float)($rowData['quantity'] ?? 0);
        $unitPrice  = (float)($rowData['unit_price'] ?? $rowData['current_unit_price'] ?? 0);
        $total      = (float)($rowData['current_total_amount'] ?? 0);
        $basePrice  = (float)($rowData['base_unit_price'] ?? 0);
        $priceIndex = (float)($rowData['price_index'] ?? 0);

        if ($qty > 0 && $unitPrice > 0 && $total > 0) {
            $computed = $qty * $unitPrice;
            if (!$this->isClose($computed, $total)) {
                $warnings[] = "Математическое несоответствие: qty({$qty}) × price({$unitPrice}) = {$computed}, но total={$total}";
                Log::info("[FormulaAwareness] Math mismatch on row: computed={$computed}, stored total={$total}");
            }
        }

        if ($priceIndex > 0 && $basePrice > 0 && $unitPrice > 0) {
            $expectedCurrent = $basePrice * $priceIndex;
            if (!$this->isClose($expectedCurrent, $unitPrice)) {
                $warnings[] = "Индекс не сходится: base({$basePrice}) × index({$priceIndex}) = {$expectedCurrent}, но current_price={$unitPrice}";
            }
        }

        return $warnings;
    }

    public function validateBatch(array $rows): array
    {
        $results = [];

        foreach ($rows as $idx => $row) {
            if ($row['is_section'] ?? false) {
                $results[$idx] = [];
                continue;
            }

            $warnings      = $this->validate($row);
            $results[$idx] = $warnings;

            if (!empty($warnings)) {
                $row['has_math_mismatch'] = true;
                $row['warnings']          = array_merge($row['warnings'] ?? [], $warnings);
            }
        }

        return $results;
    }

    public function annotate(array &$rows): void
    {
        foreach ($rows as &$row) {
            if ($row['is_section'] ?? false) {
                continue;
            }

            $warnings = $this->validate($row);

            if (!empty($warnings)) {
                $row['has_math_mismatch'] = true;
                $row['warnings']          = array_merge($row['warnings'] ?? [], $warnings);
            }
        }
    }

    private function isClose(float $a, float $b): bool
    {
        if ($b == 0) {
            return $a == 0;
        }

        return abs($a - $b) / abs($b) <= self::TOLERANCE;
    }
}
