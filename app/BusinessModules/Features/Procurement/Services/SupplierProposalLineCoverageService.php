<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Services;

use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use InvalidArgumentException;

final class SupplierProposalLineCoverageService
{
    public function evaluatePurchase(array $purchaseLines, array $requestLines, array $offeredLines): array
    {
        $purchaseLineIds = [];
        $mappingIssues = [];
        foreach ($requestLines as $line) {
            $id = is_array($line) ? filter_var($line['id'] ?? null, FILTER_VALIDATE_INT) : false;
            $purchaseId = is_array($line) ? filter_var($line['purchase_request_line_id'] ?? null, FILTER_VALIDATE_INT) : false;
            if (! is_int($id) || $id < 1 || ! is_int($purchaseId) || $purchaseId < 1) {
                $mappingIssues[] = 'invalid_request_line';

                continue;
            }
            if (isset($purchaseLineIds[$id])) {
                $mappingIssues[] = 'duplicate_request_line_identity';

                continue;
            }
            $purchaseLineIds[$id] = $purchaseId;
        }
        $mappedOffers = array_map(static function (mixed $line) use ($purchaseLineIds): mixed {
            if (! is_array($line)) {
                return $line;
            }
            $id = filter_var($line['supplier_request_line_id'] ?? null, FILTER_VALIDATE_INT);
            $line['supplier_request_line_id'] = is_int($id) ? ($purchaseLineIds[$id] ?? null) : null;

            return $line;
        }, $offeredLines);
        $result = $this->evaluate($purchaseLines, $mappedOffers);
        $requestCoverage = $this->evaluate($requestLines, $offeredLines);
        $result['issues'] = array_values(array_unique(array_merge($result['issues'], $requestCoverage['issues'], $mappingIssues)));
        sort($result['issues'], SORT_STRING);
        $result['complete'] = $result['issues'] === [];
        $result['lines'] = array_map(static function (array $line): array {
            $line['purchase_request_line_id'] = $line['supplier_request_line_id'];
            unset($line['supplier_request_line_id']);

            return $line;
        }, $result['lines']);

        return $result;
    }

    public function evaluate(array $requiredLines, array $offeredLines): array
    {
        $offers = [];
        $issues = [];
        foreach ($offeredLines as $line) {
            $id = is_array($line) ? filter_var($line['supplier_request_line_id'] ?? null, FILTER_VALIDATE_INT) : false;
            if (! is_int($id) || $id < 1) {
                $issues[] = 'unlinked_proposal_line';

                continue;
            }
            if (isset($offers[$id])) {
                $issues[] = 'duplicate_proposal_request_line';

                continue;
            }
            $offers[$id] = $line;
        }

        $coverage = [];
        $seen = [];
        foreach ($requiredLines as $line) {
            $id = is_array($line) ? filter_var($line['id'] ?? null, FILTER_VALIDATE_INT) : false;
            if (! is_int($id) || $id < 1) {
                $issues[] = 'invalid_request_line';

                continue;
            }
            if (isset($seen[$id])) {
                $issues[] = 'duplicate_request_line_identity';

                continue;
            }
            $seen[$id] = true;
            $offer = $offers[$id] ?? null;
            $required = $this->quantity($line['quantity'] ?? null, $issues, 'invalid_request_line_quantity');
            $offered = $offer === null ? null : $this->quantity($offer['quantity'] ?? null, $issues, 'invalid_proposal_line_quantity');
            $unit = trim((string) ($line['unit'] ?? ''));
            $offeredUnit = $offer === null ? null : trim((string) ($offer['unit'] ?? ''));
            $coverage[] = [
                'supplier_request_line_id' => $id,
                'required_quantity' => $required,
                'required_unit' => $unit,
                'covered_quantity' => $offered,
                'covered_unit' => $offeredUnit,
                'covered' => $required !== null && $offered !== null && $unit !== ''
                    && $required === $offered && $unit === $offeredUnit,
            ];
            unset($offers[$id]);
        }

        usort($coverage, static fn (array $left, array $right): int => $left['supplier_request_line_id'] <=> $right['supplier_request_line_id']);
        if ($coverage === [] || $offers !== [] || array_filter($coverage, static fn (array $line): bool => ! $line['covered']) !== []) {
            $issues[] = 'incomplete_request_line_coverage';
        }
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);

        return ['complete' => $issues === [], 'lines' => $coverage, 'issues' => $issues];
    }

    private function quantity(mixed $value, array &$issues, string $code): ?string
    {
        try {
            $quantity = ProcurementAwardCanonicalizer::decimal($value);
            if (ProcurementAwardCanonicalizer::compare($quantity, '0') <= 0) {
                $issues[] = $code;

                return null;
            }

            return $quantity;
        } catch (InvalidArgumentException) {
            $issues[] = $code;

            return null;
        }
    }
}
