<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\Models\Estimate;
use App\Models\EstimateItem;
use Brick\Math\BigDecimal;
use DomainException;

/** One header calculation for accepted source VAT; ordinary global-rate estimates keep their existing policy. */
final class EstimateSourceVatCalculator
{
    public const METHOD = 'source-snapshots:v1';

    public function assertStructureMutable(Estimate $estimate): void
    {
        if (($estimate->metadata['vat_calculation_method'] ?? null) === self::METHOD) {
            $this->changedBasis();
        }
    }

    /** Accepted money must be recalculated and explicitly confirmed before changing its basis. */
    public function assertHeaderUpdate(Estimate $estimate, array $updates): void
    {
        if (($estimate->metadata['vat_calculation_method'] ?? null) !== self::METHOD) {
            return;
        }
        foreach (['vat_rate', 'overhead_rate', 'profit_rate', 'contingency_amount', 'total_amount', 'total_amount_with_vat',
            'organization_id', 'project_id', 'calculation_method', 'estimate_regional_price_version_id', 'regional_price_snapshot', 'metadata'] as $field) {
            if (array_key_exists($field, $updates) && $estimate->getAttribute($field) != $updates[$field]) {
                $this->changedBasis();
            }
        }
    }

    public function assertLineUpdate(Estimate $estimate, EstimateItem $item, array $updates): void
    {
        if (($estimate->metadata['vat_calculation_method'] ?? null) !== self::METHOD) {
            return;
        }
        $this->line($estimate, $item);
        $descriptive = ['name', 'description', 'notes', 'position_number', 'estimate_section_id'];
        foreach ($updates as $field => $value) {
            if (! in_array($field, $descriptive, true) && $item->getAttribute($field) != $value) {
                $this->changedBasis();
            }
        }
    }

    private function changedBasis(): never
    {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'estimate' => 'Смета сохраняет подтверждённые суммы и НДС источников. Для изменения объёмов, цен или условий подтвердите новую оценку.',
        ])->status(409);
    }

    public function gross(Estimate $estimate, BigDecimal $netTotal): BigDecimal
    {
        if (($estimate->metadata['vat_calculation_method'] ?? null) !== self::METHOD) {
            return $netTotal->multipliedBy(BigDecimal::one()->plus(BigDecimal::of((string) $estimate->vat_rate)->withPointMovedLeft(2)));
        }
        $money = [];
        $expectedKeys = $estimate->metadata['source_money_work_keys'] ?? null;
        if (! is_array($expectedKeys) || ! array_is_list($expectedKeys) || $expectedKeys === []
            || count(array_unique($expectedKeys, SORT_STRING)) !== count($expectedKeys)) {
            throw new DomainException('source_money_work_manifest_missing');
        }
        $seen = [];
        foreach (EstimateItem::query()->where('estimate_id', $estimate->id)->whereNull('parent_work_id')->get() as $item) {
            $key = $item->metadata['source_work_item_key'] ?? null;
            if (! is_string($key) || isset($seen[$key]) || $item->is_not_accounted) {
                throw new DomainException('source_money_work_set_changed');
            }
            $seen[$key] = true;
            $money[] = $this->line($estimate, $item);
        }
        $actualKeys = array_keys($seen);
        sort($expectedKeys, SORT_STRING);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            throw new DomainException('source_money_work_set_changed');
        }
        $reserve = BigDecimal::of((string) ($estimate->contingency_amount ?? '0'));
        if (! $reserve->isZero()) {
            $reserveMoney = $estimate->metadata['source_contingency_money'] ?? null;
            if (! is_array($reserveMoney) || ! is_string($reserveMoney['net_amount'] ?? null)
                || ! $reserve->isEqualTo($reserveMoney['net_amount'])) {
                throw new DomainException('source_contingency_tax_terms_unconfirmed');
            }
            $money[] = $reserveMoney;
        }
        $totals = (new SourceMoneyVatBasis)->totals($money);
        if (! $netTotal->isEqualTo($totals['net_amount'])) {
            throw new DomainException('source_money_header_net_mismatch');
        }

        return BigDecimal::of($totals['gross_amount']);
    }

    public function line(Estimate $estimate, EstimateItem $item): array
    {
        $record = $item->metadata['generated_source_money'] ?? null;
        if ((int) $item->estimate_id !== (int) $estimate->id || ! is_array($record)) {
            throw new DomainException('source_money_line_missing');
        }

        return (new SourceMoneyVatBasis)->accepted($record, [
            'organization_id' => (int) $estimate->organization_id, 'project_id' => (int) $estimate->project_id,
            'session_id' => (int) ($estimate->metadata['generation_session_id'] ?? 0),
            'work_item_key' => $item->metadata['source_work_item_key'] ?? null,
            'measurement_unit_id' => (int) $item->measurement_unit_id, 'quantity' => (string) $item->quantity,
            'stored_unit_price' => (string) $item->unit_price, 'net_amount' => (string) $item->total_amount,
        ]);
    }
}
