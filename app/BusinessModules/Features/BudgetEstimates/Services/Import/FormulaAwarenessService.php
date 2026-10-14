<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation\MathValidatorService;

class FormulaAwarenessService
{
    public function validate(array $rowData): array
    {
        $validator = new MathValidatorService;
        $priceValue = $rowData['unit_price'] ?? $rowData['current_unit_price'] ?? null;
        $price = $priceValue !== null ? (float) $priceValue : null;

        return array_merge(
            $validator->validateRow(
                isset($rowData['quantity']) ? (float) $rowData['quantity'] : null,
                $price,
                isset($rowData['current_total_amount']) ? (float) $rowData['current_total_amount'] : null,
            ),
            $validator->validateCoefficients(
                isset($rowData['base_unit_price']) ? (float) $rowData['base_unit_price'] : null,
                isset($rowData['price_index']) ? (float) $rowData['price_index'] : null,
                $price,
            ),
        );
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

}
