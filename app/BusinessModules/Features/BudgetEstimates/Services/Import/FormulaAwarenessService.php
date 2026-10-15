<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;
use App\BusinessModules\Features\BudgetEstimates\Services\Import\Validation\MathValidatorService;

class FormulaAwarenessService
{
    public function validate(array $rowData): array
    {
        $comparison = $this->comparison($rowData);
        if (($rowData['is_section'] ?? false) || $comparison['informative']) {
            return [];
        }
        $validator = new MathValidatorService;

        return array_merge(
            $validator->validateRow(
                $comparison['quantity'],
                $comparison['price'],
                $comparison['total'],
                $comparison['charges'],
            ),
            ($rowData['math_price_derived_from_total'] ?? false) ? [] : $validator->validateCoefficients(
                isset($rowData['base_unit_price']) ? (float) $rowData['base_unit_price'] : null,
                isset($rowData['price_index']) ? (float) $rowData['price_index'] : null,
                $comparison['price'],
                $comparison['coefficient_tolerance'],
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

            $results[$idx] = $this->validate($row);
        }

        return $results;
    }

    public function annotate(array &$rows): void
    {
        foreach ($rows as &$row) {
            if ($row['is_section'] ?? false) {
                continue;
            }

            $comparison = $this->comparison($row);
            $row['is_informative'] = $comparison['informative'];
            $row['math_expected_amount'] = $comparison['informative'] ? null : (new MathValidatorService)->calculateTotal($comparison['quantity'], $comparison['price'], $comparison['charges']);
            $row['math_comparison_basis'] = $comparison['informative'] ? null : ($comparison['includes_charges'] ? 'quantity_price_with_charges' : 'quantity_price');
            $warnings = $this->validate($row);

            if (!empty($warnings)) {
                $row['has_math_mismatch'] = true;
                $row['warnings']          = array_merge($row['warnings'] ?? [], $warnings);
            }
        }
    }

    private function comparison(array $row): array
    {
        $informative = (new ImportRowPolicy)->isInformative(new EstimateImportRowDTO(
            itemName: (string) ($row['item_name'] ?? $row['name'] ?? ''),
            code: isset($row['code']) ? (string) $row['code'] : null,
            isNotAccounted: (bool) ($row['is_not_accounted'] ?? false),
        ));
        $raw = $row['raw_data'] ?? [];
        $modernRoot = is_array($raw) && ($raw['financial_structure']['layout'] ?? null) === 'grand_modern_2020' && !($row['is_sub_item'] ?? false);
        $includesCharges = !($row['is_sub_item'] ?? false) && ($modernRoot || isset($row['overhead_amount']) || isset($row['profit_amount']));
        $price = $row['unit_price'] ?? $row['current_unit_price'] ?? null;
        $quantity = isset($row['quantity']) ? (float) $row['quantity'] : null;
        $baseTotal = is_array($raw) ? ($raw['base_total_amount'] ?? $raw['component_sources']['base_direct_total'] ?? null) : null;
        $coefficientTolerance = 0.05;
        if ($modernRoot && $baseTotal !== null && $quantity !== null && $quantity != 0 && isset($row['price_index'])) {
            $coefficientTolerance = max($coefficientTolerance, 0.005 * (abs((float) $row['price_index']) + 1) / abs($quantity));
        }

        return [
            'informative' => $informative,
            'includes_charges' => $includesCharges,
            'coefficient_tolerance' => $coefficientTolerance,
            'quantity' => $quantity,
            'price' => $price !== null ? (float) $price : null,
            'total' => isset($row['current_total_amount']) ? (float) $row['current_total_amount'] : null,
            'charges' => $includesCharges ? (float) ($row['overhead_amount'] ?? 0) + (float) ($row['profit_amount'] ?? 0) : 0.0,
        ];
    }
}
