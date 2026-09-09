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
            || ($row->currentTotalAmount ?? 0) != 0);
    }

    public function isInformative(EstimateImportRowDTO $row): bool
    {
        $name = mb_strtolower($row->itemName);
        $code = mb_strtolower($row->code ?? '');
        $aggregates = ['от(зт)', 'эм', 'отм(зтм)', 'м', 'зтм', 'зт', 'от', 'отм', 'мат'];

        return (in_array($name, $aggregates) && (empty($code) || strlen($code) <= 2))
            || str_starts_with($code, '4-100-')
            || str_contains($name, 'всего по позиции');
    }

    public function totalAmount(EstimateImportRowDTO $row, ?float $overhead = null, ?float $profit = null): float
    {
        $amount = (float) ($row->currentTotalAmount ?? (($row->quantity ?? 0) * ($row->unitPrice ?? 0)));
        $overhead ??= $row->overheadAmount ?? 0;
        $profit ??= $row->profitAmount ?? 0;

        return ! $this->isInformative($row) && ! $row->isSubItem && $amount > 0 && ($overhead > 0 || $profit > 0)
            ? $amount
            : $amount + $overhead + $profit;
    }
}
