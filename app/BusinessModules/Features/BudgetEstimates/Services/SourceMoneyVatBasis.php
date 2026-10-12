<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation\EvaluationVatProjection;
use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;
use DomainException;

/** Preserves the accepted line-level VAT rounding, including zero VAT on tiny VAT-inclusive prices. */
final class SourceMoneyVatBasis
{
    public const CONTRACT = 'source-money:v1';

    public function seal(array $identity, array $money): array
    {
        $record = ['contract' => self::CONTRACT, ...$identity, 'money' => $money];
        $this->assertRecord($record);

        return [...$record, 'fingerprint' => hash('sha256', CanonicalPipelineJson::encode($record))];
    }

    public function accepted(array $record, array $currentIdentity): array
    {
        $fingerprint = $record['fingerprint'] ?? null;
        unset($record['fingerprint']);
        if (! is_string($fingerprint) || ! hash_equals($fingerprint, hash('sha256', CanonicalPipelineJson::encode($record)))) {
            throw new DomainException('source_money_fingerprint_invalid');
        }
        $this->assertRecord($record);
        foreach (['organization_id', 'project_id', 'session_id', 'work_item_key', 'measurement_unit_id'] as $key) {
            if (($record[$key] ?? null) !== ($currentIdentity[$key] ?? null)) {
                throw new DomainException('source_money_identity_changed');
            }
        }
        foreach (['quantity', 'stored_unit_price', 'net_amount'] as $key) {
            $expected = $key === 'net_amount' ? $record['money']['net_amount'] : $record[$key];
            if (! is_string($currentIdentity[$key] ?? null) || ! BigDecimal::of($expected)->isEqualTo($currentIdentity[$key])) {
                throw new DomainException('source_money_basis_changed');
            }
        }

        return $record['money'];
    }

    /** @param list<array> $moneyRecords */
    public function totals(array $moneyRecords): array
    {
        $net = $vat = $gross = BigDecimal::zero();
        foreach ($moneyRecords as $money) {
            $this->assertMoney($money);
            $net = $net->plus($money['net_amount']);
            $vat = $vat->plus($money['vat_amount']);
            $gross = $gross->plus($money['gross_amount']);
        }

        return ['net_amount' => OrdinaryEstimateDecimal::money($net), 'vat_amount' => OrdinaryEstimateDecimal::money($vat),
            'gross_amount' => OrdinaryEstimateDecimal::money($gross)];
    }

    private function assertRecord(array $record): void
    {
        if (($record['contract'] ?? null) !== self::CONTRACT || ($record['currency'] ?? null) !== 'RUB'
            || ! is_array($record['money'] ?? null) || ! is_string($record['source_reference'] ?? null) || trim($record['source_reference']) === ''
            || ! is_string($record['source_hash'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $record['source_hash']) !== 1
            || ! is_string($record['work_item_key'] ?? null) || trim($record['work_item_key']) === '') {
            throw new DomainException('source_money_contract_invalid');
        }
        foreach (['organization_id', 'project_id', 'session_id', 'measurement_unit_id'] as $key) {
            if (! is_int($record[$key] ?? null) || $record[$key] < 1) {
                throw new DomainException('source_money_scope_invalid');
            }
        }
        foreach (['quantity', 'stored_unit_price'] as $key) {
            if (! is_string($record[$key] ?? null) || preg_match('/\A[0-9]+(?:\.[0-9]{1,18})?\z/', $record[$key]) !== 1
                || ! BigDecimal::of($record[$key])->isGreaterThan(0)) {
                throw new DomainException('source_money_basis_invalid');
            }
        }
        $this->assertMoney($record['money']);
    }

    private function assertMoney(array $money): void
    {
        foreach (['net_amount', 'vat_amount', 'gross_amount'] as $key) {
            if (! is_string($money[$key] ?? null) || preg_match('/\A[0-9]+\.[0-9]{2}\z/', $money[$key]) !== 1) {
                throw new DomainException('source_money_amount_invalid');
            }
        }
        if (! BigDecimal::of($money['net_amount'])->plus($money['vat_amount'])->isEqualTo($money['gross_amount'])) {
            throw new DomainException('source_money_amount_not_reconciled');
        }
        try {
            $expected = (new EvaluationVatProjection)->amount($money['quoted_amount'] ?? '', $money);
        } catch (\Throwable $exception) {
            throw new DomainException('source_money_tax_terms_invalid', 0, $exception);
        }
        foreach (['net_amount', 'vat_amount', 'gross_amount'] as $key) {
            if ($expected[$key] !== $money[$key]) {
                throw new DomainException('source_money_tax_calculation_mismatch');
            }
        }
    }
}
