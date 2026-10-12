<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Domain\OrdinaryEstimateDecimal;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Converts accepted source money after its own line-level rounding, never by recalculating RUB VAT. */
final class EvaluationCurrencyConversion
{
    public const CONTRACT = 'evaluation-currency-conversion:v1';

    public function terms(array $selection): array
    {
        $currency = $selection['from_currency'] ?? null;
        $rate = $selection['rub_per_unit'] ?? null;
        $date = $selection['as_of_date'] ?? null;
        $reference = $selection['rate_source_reference'] ?? null;
        if (! is_string($currency) || preg_match('/\A[A-Z]{3}\z/', $currency) !== 1 || $currency === 'RUB'
            || ($selection['to_currency'] ?? null) !== 'RUB'
            || ! is_string($rate) || strlen($rate) > 30 || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,12})?\z/', $rate) !== 1
            || ! BigDecimal::of($rate)->isGreaterThan(0) || BigDecimal::of($rate)->isGreaterThan('1000000')
            || ! is_string($date) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $date) !== 1
            || ! ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) || $parsed->format('Y-m-d') !== $date
            || ! is_string($reference) || trim($reference) === '' || mb_strlen($reference) > 240
            || preg_match('/[\x00-\x1F\x7F]/', $reference) === 1) {
            throw new InvalidArgumentException('evaluation_currency_terms_invalid');
        }

        return ['from_currency' => $currency, 'to_currency' => 'RUB', 'rub_per_unit' => $rate,
            'as_of_date' => $date, 'rate_source_reference' => $reference];
    }

    public function snapshot(array $source, string $quantity, array $selection): array
    {
        $terms = $this->terms($selection);
        if (($source['currency'] ?? null) !== $terms['from_currency'] || isset($source['currency_conversion'])
            || isset($source['calculation_basis']) || ($source['verified'] ?? null) !== true
            || ! in_array($source['source_type'] ?? null, ['catalog', 'supplier', 'verified_analog'], true)
            || ! is_string($source['unit_price'] ?? null) || ! BigDecimal::of($quantity)->isGreaterThan(0)) {
            throw new InvalidArgumentException('evaluation_currency_source_invalid');
        }
        foreach (['fact_id' => '/\Afact:[a-f0-9]{64}\z/', 'decision_id' => '/\Adecision:[a-f0-9]{64}\z/'] as $key => $pattern) {
            if (! is_string($selection[$key] ?? null) || preg_match($pattern, $selection[$key]) !== 1) {
                throw new InvalidArgumentException('evaluation_currency_confirmation_missing');
            }
        }
        $basis = ['contract' => self::CONTRACT, 'quantity' => $quantity, 'source_snapshot' => $source,
            'source_snapshot_hash' => hash('sha256', CanonicalPipelineJson::encode($source)),
            'rate' => [...$terms, 'fact_id' => $selection['fact_id'], 'decision_id' => $selection['decision_id']]];

        return [...$source, 'currency' => 'RUB',
            'unit_price' => (string) BigDecimal::of($source['unit_price'])->multipliedBy($terms['rub_per_unit'])->strippedOfTrailingZeros(),
            'currency_conversion' => $basis];
    }

    public function sourceMoney(array $basis): array
    {
        $source = $basis['source_snapshot'] ?? null;
        $quantity = $basis['quantity'] ?? null;
        $terms = $this->terms(is_array($basis['rate'] ?? null) ? $basis['rate'] : []);
        if (($basis['contract'] ?? null) !== self::CONTRACT || ! is_array($source) || isset($source['currency_conversion'])
            || isset($source['calculation_basis']) || ($source['currency'] ?? null) !== $terms['from_currency']
            || ! is_string($basis['source_snapshot_hash'] ?? null)
            || ! hash_equals($basis['source_snapshot_hash'], hash('sha256', CanonicalPipelineJson::encode($source)))
            || ! is_string($quantity) || preg_match('/\A[0-9]+(?:\.[0-9]{1,18})?\z/', $quantity) !== 1
            || ! BigDecimal::of($quantity)->isGreaterThan(0) || ! is_string($source['unit_price'] ?? null)) {
            throw new InvalidArgumentException('evaluation_currency_basis_invalid');
        }
        // Recheck the persisted proof identifiers and original source semantics.
        $this->snapshot($source, $quantity, $basis['rate']);
        $quoted = (new EvaluationLineAmount)->calculate(BigDecimal::of($quantity), BigDecimal::of($source['unit_price']), $source);

        return (new EvaluationVatProjection)->amount($quoted, $source);
    }

    public function money(array $basis): array
    {
        $source = $this->sourceMoney($basis);
        $rate = $basis['rate']['rub_per_unit'];
        $net = OrdinaryEstimateDecimal::money(BigDecimal::of($source['net_amount'])->multipliedBy($rate));
        $gross = OrdinaryEstimateDecimal::money(BigDecimal::of($source['gross_amount'])->multipliedBy($rate));
        // Preserve the payable amount and reconcile component rounding explicitly.
        $vat = OrdinaryEstimateDecimal::money(BigDecimal::of($gross)->minus($net));
        $directVat = OrdinaryEstimateDecimal::money(BigDecimal::of($source['vat_amount'])->multipliedBy($rate));

        return ['net_amount' => $net, 'vat_amount' => $vat, 'gross_amount' => $gross,
            'quoted_amount' => OrdinaryEstimateDecimal::money(BigDecimal::of($source['quoted_amount'])->multipliedBy($rate)),
            'vat_mode' => $source['vat_mode'], 'vat_rate' => $source['vat_rate'],
            'currency_conversion' => $basis, 'source_money' => $source,
            'vat_conversion_rounding_adjustment' => (string) BigDecimal::of($vat)->minus($directVat)->toScale(2)];
    }
}
