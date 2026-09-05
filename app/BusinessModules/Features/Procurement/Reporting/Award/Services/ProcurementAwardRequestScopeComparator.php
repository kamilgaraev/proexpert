<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Award\Services;

use App\BusinessModules\Core\Reporting\Support\CanonicalJson;
use App\BusinessModules\Features\Procurement\Reporting\Award\Support\ProcurementAwardCanonicalizer;
use InvalidArgumentException;

final class ProcurementAwardRequestScopeComparator
{
    public function equivalent(array $rows): bool
    {
        $versionsByRequest = [];
        foreach ($rows as $row) {
            $versionsByRequest[(int) ($row['supplier_request_id'] ?? 0)][] = [
                $row['supplier_request_version_id'] ?? null,
                $row['supplier_request_version_hash'] ?? null,
            ];
        }
        foreach ($versionsByRequest as $versions) {
            if (count(array_unique($versions, SORT_REGULAR)) !== 1) {
                return false;
            }
        }
        if (count($versionsByRequest) === 1) {
            return true;
        }

        $scopes = [];
        foreach ($rows as $row) {
            $scope = $this->scope($row['comparison_request_lines'] ?? null);
            if ($scope === null) {
                return false;
            }
            $scopes[] = $scope;
        }

        return count(array_unique($scopes)) === 1;
    }

    private function scope(mixed $lines): ?string
    {
        if (! is_array($lines) || $lines === []) {
            return null;
        }
        $scope = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                return null;
            }
            $lineId = filter_var($line['purchase_request_line_id'] ?? null, FILTER_VALIDATE_INT);
            $unit = $line['unit'] ?? null;
            if (! is_int($lineId) || $lineId < 1 || isset($scope[$lineId]) || ! is_string($unit) || trim($unit) === '') {
                return null;
            }
            try {
                $quantity = ProcurementAwardCanonicalizer::decimal($line['quantity'] ?? null);
            } catch (InvalidArgumentException) {
                return null;
            }
            if (ProcurementAwardCanonicalizer::compare($quantity, '0') <= 0) {
                return null;
            }
            $scope[$lineId] = [
                'purchase_request_line_id' => $lineId,
                'quantity' => $quantity,
                'unit' => $unit,
                'name' => $line['name'] ?? null,
                'material_id' => $line['material_id'] ?? null,
                'specification_hash' => $line['specification_hash'] ?? null,
                'specification' => $line['specification'] ?? null,
            ];
        }
        ksort($scope, SORT_NUMERIC);

        try {
            return hash('sha256', CanonicalJson::encode(array_values($scope)));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
