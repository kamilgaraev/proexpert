<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services\Versioning;

use App\BusinessModules\Features\BudgetEstimates\Services\EstimateSourceVatCalculator;
use App\BusinessModules\Features\BudgetEstimates\Services\SourceMoneyVatBasis;
use App\Models\Estimate;
use Brick\Math\BigDecimal;
use DomainException;

/** Restore validates frozen source money before changing status, history or structure. */
final class SourceMoneySnapshotGuard
{
    public function assertAccepted(Estimate $estimate, array $snapshot): void
    {
        $header = $snapshot['estimate'] ?? [];
        if (($header['metadata']['vat_calculation_method'] ?? null) !== EstimateSourceVatCalculator::METHOD) {
            return;
        }
        if (($snapshot['schema_version'] ?? null) !== 2 || (int) ($header['id'] ?? 0) !== (int) $estimate->id
            || (int) ($header['organization_id'] ?? 0) !== (int) $estimate->organization_id
            || (int) ($header['project_id'] ?? 0) !== (int) $estimate->project_id
            || ! is_array($snapshot['sections'] ?? null) || ! is_array($snapshot['unsectioned_items'] ?? null)) {
            throw new DomainException('source_money_restore_scope_mismatch');
        }
        $records = [];
        $seen = [];
        $rows = 0;
        $visitItems = function (array $items, bool $root, int $depth) use (&$visitItems, &$records, &$seen, &$rows, $estimate, $header): void {
            if ($depth > 64 || ! array_is_list($items)) {
                throw new DomainException('source_money_restore_structure_invalid');
            }
            foreach ($items as $item) {
                if (! is_array($item) || ++$rows > 10000 || ! is_array($item['children'] ?? null)) {
                    throw new DomainException('source_money_restore_structure_invalid');
                }
                if ($root) {
                    $key = $item['metadata']['source_work_item_key'] ?? null;
                    if (! is_string($key) || isset($seen[$key])) {
                        throw new DomainException('source_money_restore_duplicate_work');
                    }
                    $seen[$key] = true;
                    if (! is_string($item['quantity'] ?? null) || ! is_string($item['quantity_total'] ?? null)
                        || ! is_string($item['current_total_amount'] ?? null)
                        || ! BigDecimal::of($item['quantity'])->isEqualTo($item['quantity_total'])
                        || ! BigDecimal::of($item['current_total_amount'])->isEqualTo($item['total_amount'] ?? '-1')) {
                        throw new DomainException('source_money_restore_shadow_basis_mismatch');
                    }
                    $money = (new SourceMoneyVatBasis)->accepted($item['metadata']['generated_source_money'] ?? [], [
                        'organization_id' => (int) $estimate->organization_id, 'project_id' => (int) $estimate->project_id,
                        'session_id' => (int) ($header['metadata']['generation_session_id'] ?? 0), 'work_item_key' => $key,
                        'measurement_unit_id' => (int) ($item['measurement_unit']['id'] ?? 0),
                        'quantity' => $item['quantity'] ?? null, 'stored_unit_price' => $item['unit_price'] ?? null,
                        'net_amount' => $item['total_amount'] ?? null,
                    ]);
                    if (($item['is_not_accounted'] ?? false) !== true) {
                        $records[] = $money;
                    }
                }
                $visitItems($item['children'], false, $depth + 1);
            }
        };
        $visitSections = function (array $sections, int $depth) use (&$visitSections, $visitItems, &$rows): void {
            if ($depth > 64 || ! array_is_list($sections)) {
                throw new DomainException('source_money_restore_structure_invalid');
            }
            foreach ($sections as $section) {
                if (! is_array($section) || ++$rows > 10000 || ! is_array($section['items'] ?? null) || ! is_array($section['children'] ?? null)) {
                    throw new DomainException('source_money_restore_structure_invalid');
                }
                $visitItems($section['items'], true, 0);
                $visitSections($section['children'], $depth + 1);
            }
        };
        $visitSections($snapshot['sections'] ?? [], 0);
        $visitItems($snapshot['unsectioned_items'] ?? [], true, 0);
        $expectedKeys = $header['metadata']['source_money_work_keys'] ?? null;
        $actualKeys = array_keys($seen);
        if (! is_array($expectedKeys) || ! array_is_list($expectedKeys)) {
            throw new DomainException('source_money_restore_manifest_missing');
        }
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($expectedKeys !== $actualKeys) {
            throw new DomainException('source_money_restore_work_set_changed');
        }
        $reserve = BigDecimal::of((string) ($snapshot['totals']['contingency_amount'] ?? '0'));
        if (! $reserve->isZero()) {
            $money = $header['metadata']['source_contingency_money'] ?? null;
            if (! is_array($money) || ! $reserve->isEqualTo($money['net_amount'] ?? '-1')) {
                throw new DomainException('source_money_restore_reserve_invalid');
            }
            $records[] = $money;
        }
        $totals = (new SourceMoneyVatBasis)->totals($records);
        if (! BigDecimal::of((string) ($snapshot['totals']['total_amount'] ?? '-1'))->isEqualTo($totals['net_amount'])
            || ! BigDecimal::of((string) ($snapshot['totals']['total_amount_with_vat'] ?? '-1'))->isEqualTo($totals['gross_amount'])) {
            throw new DomainException('source_money_restore_totals_mismatch');
        }
    }
}
