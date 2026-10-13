<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Domain\Evaluation;

use App\BusinessModules\Addons\EstimateGeneration\Pricing\ConfirmedCommercialPriceConditions;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

final class ScenarioEstimateCalculator
{
    public function calculate(array $scenarios, array $scope, array $requirements, bool $scopeConfirmed = false): array
    {
        if ($scope === [] || ! array_is_list($scope) || count($scope) > 50
            || count($scope) !== count(array_unique($scope))
            || count(array_filter($scope, static fn ($section): bool => is_string($section) && $section !== '' && strlen($section) <= 120)) !== count($scope)
            || count($scenarios) < 1 || count($scenarios) > 3) {
            throw new InvalidArgumentException('scenario_scope_or_count_invalid');
        }
        $ids = [];
        $results = [];
        $assumed = false;
        $complete = $requirements === [];
        $scenarioCostsComplete = true;
        $currencyConversionRequired = false;
        foreach ($scenarios as $scenario) {
            $id = $scenario['id'] ?? null;
            if (! is_string($id) || ! in_array($id, ['minimum', 'base', 'maximum'], true) || isset($ids[$id])
                || ! is_array($scenario['positions'] ?? null) || count($scenario['positions']) > 10000
                || ! is_array($scenario['assumptions'] ?? null)) {
                throw new InvalidArgumentException('scenario_definition_invalid');
            }
            $ids[$id] = true;
            $assumed = $assumed || $scenario['assumptions'] !== [];
            $totals = [];
            $netTotals = $vatTotals = $grossTotals = [];
            $taxUnknown = [];
            $positions = [];
            $unknown = [];
            $priceConditions = [];
            $groups = [];
            $positionIds = [];
            foreach ($scenario['positions'] as $position) {
                $key = $position['key'] ?? null;
                if (! is_string($key) || $key === '' || isset($positionIds[$key])
                    || ! in_array($position['section'] ?? null, $scope, true)) {
                    throw new InvalidArgumentException('scenario_position_scope_invalid');
                }
                $positionIds[$key] = true;
                $group = $position['exclusive_group'] ?? null;
                if ($group !== null) {
                    if (! is_string($group) || $group === '' || isset($groups[$group])) {
                        throw new InvalidArgumentException('scenario_exclusive_options_conflict');
                    }
                    $groups[$group] = true;
                }
                $quantity = $this->number($position['quantity'] ?? null);
                $price = is_array($position['price_snapshot'] ?? null) ? $position['price_snapshot'] : null;
                if ($quantity !== null && is_array($price['currency_conversion'] ?? null)) {
                    // Scenario volumes are evaluated from the original source, not from a previously rounded tuple.
                    $basis = $price['currency_conversion'];
                    (new EvaluationCurrencyConversion)->sourceMoney($basis);
                    $price = (new EvaluationCurrencyConversion)->snapshot($basis['source_snapshot'], (string) $quantity, $basis['rate']);
                }
                if ($quantity !== null && ($price['source_type'] ?? null) === 'supplier'
                    && (! is_string($price['price_quantity'] ?? null) || ! $quantity->isEqualTo($price['price_quantity']))) {
                    $price['applicable'] = false;
                }
                $unitPrice = $this->number($price['unit_price'] ?? null);
                $currency = $price['currency'] ?? null;
                $hasSourcePrice = $price !== null && $unitPrice !== null && ($price['verified'] ?? false) === true
                    && is_string($position['unit'] ?? null) && trim($position['unit']) !== ''
                    && in_array($price['source_type'] ?? null, ['normative', 'catalog', 'supplier', 'verified_analog'], true)
                    && is_string($price['source_reference'] ?? null) && $price['source_reference'] !== ''
                    && ($price['unit'] ?? null) === ($position['unit'] ?? null);
                $hasPrice = $hasSourcePrice && is_string($currency) && preg_match('/\A[A-Z]{3}\z/', $currency) === 1
                    && ($price['applicable'] ?? true) === true
                    && $this->validDate($price['as_of_date'] ?? null)
                    && (($price['stale'] ?? false) !== true || ($price['stale_accepted'] ?? false) === true);
                $amount = null;
                $money = null;
                $status = $quantity === null ? 'quantity_unknown' : (! $hasPrice ? ($hasSourcePrice ? 'price_conditions_unknown' : 'price_unknown') : 'calculated');
                if ($quantity !== null && $hasPrice) {
                    $amount = (new EvaluationLineAmount)->calculate($quantity, $unitPrice, $price);
                    $totals[$currency] = (string) BigDecimal::of($totals[$currency] ?? '0')->plus($amount)->toScale(2);
                    if (($price['conditions_missing'] ?? []) !== []) {
                        $priceConditions[] = ['key' => $key, 'conditions' => $price['conditions_missing']];
                    }
                    try {
                        $money = (new EvaluationVatProjection)->amount($amount, $price);
                        $netTotals[$currency] = (string) BigDecimal::of($netTotals[$currency] ?? '0')->plus($money['net_amount'])->toScale(2);
                        $vatTotals[$currency] = (string) BigDecimal::of($vatTotals[$currency] ?? '0')->plus($money['vat_amount'])->toScale(2);
                        $grossTotals[$currency] = (string) BigDecimal::of($grossTotals[$currency] ?? '0')->plus($money['gross_amount'])->toScale(2);
                    } catch (InvalidArgumentException) {
                        $taxUnknown[] = $key;
                    }
                } else {
                    $unknown[] = ['key' => $key, 'reason' => $status];
                }
                $positions[] = [...$position, 'quantity' => $quantity === null ? null : (string) $quantity,
                    'price_snapshot' => $price,
                    'unit_price' => $hasSourcePrice ? (string) $unitPrice : null, 'total_cost' => $amount, 'pricing_status' => $status,
                    'money_breakdown' => $money];
            }
            // Linking delivery does not invent its price: the linked position must be fully priced in this scenario.
            $byKey = array_column($positions, null, 'key');
            foreach ($positions as $positionIndex => $position) {
                $price = $position['price_snapshot'];
                $deliveryKey = is_array($price) ? ($price['delivery_work_item_key'] ?? null) : null;
                $linked = is_string($deliveryKey) ? ($byKey[$deliveryKey] ?? null) : null;
                if ($linked === null || $deliveryKey === $position['key'] || ($linked['coverage_requirement'] ?? $linked['work_key'] ?? null) !== 'delivery'
                    || ! is_string($position['entity_id'] ?? null) || ($linked['entity_id'] ?? null) !== $position['entity_id']
                    || ($linked['section'] ?? null) !== $position['section'] || ($linked['pricing_status'] ?? null) !== 'calculated'
                    || ($linked['price_snapshot']['conditions_missing'] ?? []) !== [] || ($linked['money_breakdown'] ?? null) === null
                    || ($linked['price_snapshot']['currency'] ?? null) !== ($price['currency'] ?? null)
                    || $this->number($linked['total_cost'] ?? null)?->isGreaterThan(0) !== true) {
                    continue;
                }
                $price['conditions_missing'] = array_values(array_diff($price['conditions_missing'] ?? [], ConfirmedCommercialPriceConditions::DELIVERY_CONDITIONS));
                $price['delivery_cost_basis'] = ['work_item_key' => $deliveryKey, 'source_reference' => $linked['price_snapshot']['source_reference'],
                    'source_hash' => $linked['price_snapshot']['source_hash'] ?? null];
                $positions[$positionIndex]['price_snapshot'] = $price;
            }
            $resolvedByKey = array_column($positions, null, 'key');
            $priceConditions = array_values(array_filter(array_map(static function (array $entry) use ($resolvedByKey): array {
                return ['key' => $entry['key'], 'conditions' => $resolvedByKey[$entry['key']]['price_snapshot']['conditions_missing'] ?? $entry['conditions']];
            }, $priceConditions), static fn (array $entry): bool => $entry['conditions'] !== []));
            $contingency = null;
            if (isset($scenario['contingency'])) {
                if (! is_array($scenario['contingency'])) {
                    throw new InvalidArgumentException('scenario_contingency_invalid');
                }
                $contingency = (new EvaluationContingency)->component($scenario['contingency']);
                $totals['RUB'] = (string) BigDecimal::of($totals['RUB'] ?? '0')->plus($contingency['money']['quoted_amount'])->toScale(2);
                $netTotals['RUB'] = (string) BigDecimal::of($netTotals['RUB'] ?? '0')->plus($contingency['money']['net_amount'])->toScale(2);
                $vatTotals['RUB'] = (string) BigDecimal::of($vatTotals['RUB'] ?? '0')->plus($contingency['money']['vat_amount'])->toScale(2);
                $grossTotals['RUB'] = (string) BigDecimal::of($grossTotals['RUB'] ?? '0')->plus($contingency['money']['gross_amount'])->toScale(2);
            }
            ksort($totals);
            $currencyConversionRequired = $currencyConversionRequired || array_diff(array_keys($totals), ['RUB']) !== [];
            $costsComplete = $unknown === [] && $priceConditions === [] && $positions !== [];
            $scenarioCostsComplete = $scenarioCostsComplete && $costsComplete && $taxUnknown === [];
            $complete = $complete && $costsComplete && $taxUnknown === [];
            $results[] = ['id' => $id, 'technology' => $scenario['technology'] ?? null, 'assumptions' => $scenario['assumptions'],
                'positions' => $positions, 'known_subtotals' => $totals, 'unpriced_positions' => $unknown,
                'net_subtotals' => $netTotals, 'vat_subtotals' => $vatTotals, 'gross_subtotals' => $grossTotals,
                'tax_breakdown_unknown_positions' => $taxUnknown,
                'payable_total_by_currency' => $costsComplete && $taxUnknown === [] ? $grossTotals : null,
                'price_conditions_unresolved' => $priceConditions,
                'quoted_total_by_currency' => $costsComplete ? $totals : null,
                'total_by_currency' => $costsComplete && $taxUnknown === [] ? $grossTotals : null,
                ...($contingency === null ? [] : ['contingency' => $contingency])];
        }
        $range = null;
        if (count($results) > 1 && $scenarioCostsComplete) {
            $currencies = array_values(array_unique(array_merge(...array_map(static fn (array $scenario): array => array_keys($scenario['known_subtotals']), $results))));
            $range = [];
            foreach ($currencies as $currency) {
                $values = array_map(static fn (array $scenario): ?string => $scenario['total_by_currency'][$currency] ?? null, $results);
                if (in_array(null, $values, true)) {
                    $range = null;
                    break;
                }
                usort($values, static fn (string $left, string $right): int => BigDecimal::of($left)->compareTo($right));
                $range[$currency] = ['minimum' => $values[0], 'maximum' => $values[count($values) - 1]];
            }
        }

        return ['result_class' => ($assumed || count($results) > 1 ? EstimateResultClass::Scenario
            : ($complete && $scopeConfirmed && ! $currencyConversionRequired ? EstimateResultClass::VerifiedScope : EstimateResultClass::Refined))->value,
            'selected_scope' => array_values($scope), 'missing_requirements' => $requirements, 'scenarios' => $results,
            'scenario_range' => $range, 'range_kind' => $range === null ? null : 'scenario', 'accuracy_calibrated' => false,
            'can_confirm_for_apply' => $complete && ! $currencyConversionRequired, 'currency_conversion_required' => $currencyConversionRequired, 'scope_confirmed' => $scopeConfirmed];
    }

    private function number(mixed $value): ?BigDecimal
    {
        if ($value === null) {
            return null;
        }
        if ((! is_string($value) && ! is_int($value)) || preg_match('/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,18})?\z/', (string) $value) !== 1 || strlen((string) $value) > 48) {
            throw new InvalidArgumentException('scenario_decimal_invalid');
        }

        return BigDecimal::of((string) $value);
    }

    private function validDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
