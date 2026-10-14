<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Import;

use App\BusinessModules\Features\BudgetEstimates\DTOs\EstimateImportRowDTO;

final class ImportRowPolicy
{
    public function __construct(private readonly ImportRowMapper $mapper = new ImportRowMapper) {}

    public function shouldImport(EstimateImportRowDTO $row): bool
    {
        if (is_array($row->rawData) && $this->mapper->isTechnicalRow($row->rawData)) {
            return false;
        }

        return ! $row->isFooter && ($row->isSection
            || ($row->quantity ?? 0) != 0
            || ($row->unitPrice ?? 0) != 0
            || ($row->currentTotalAmount ?? 0) != 0
            || (trim($row->itemName) !== '' && ($row->quantity !== null
                || $row->unitPrice !== null
                || $row->currentTotalAmount !== null)));
    }

    public function isInformative(EstimateImportRowDTO $row): bool
    {
        $name = mb_strtolower($row->itemName);
        $code = mb_strtolower($row->code ?? '');
        $aggregates = ['от(зт)', 'эм', 'отм(зтм)', 'м', 'зтм', 'зт', 'от', 'отм', 'мат'];

        return $row->isNotAccounted
            || (in_array($name, $aggregates) && (empty($code) || strlen($code) <= 2))
            || str_starts_with($code, '4-100-')
            || str_contains($name, 'всего по позиции');
    }

    public function totalAmount(EstimateImportRowDTO $row, ?float $overhead = null, ?float $profit = null): float
    {
        $amount = (float) ($row->currentTotalAmount ?? (($row->quantity ?? 0) * ($row->unitPrice ?? 0)));
        $overhead ??= $row->overheadAmount ?? 0;
        $profit ??= $row->profitAmount ?? 0;

        $hasFinalTotal = ! $row->isSubItem && $row->currentTotalAmount !== null
            && (! $this->isInformative($row) || ($row->rawData['financial_structure']['layout'] ?? null) === 'grand_modern_2020');

        return $hasFinalTotal
            ? $amount + ($row->overheadAmount === null ? $overhead : 0) + ($row->profitAmount === null ? $profit : 0)
            : $amount + $overhead + $profit;
    }
}
