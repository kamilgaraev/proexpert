<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\Procurement\Reporting\Supply\Services;

use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyLineFact;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyLineMetric;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyReliabilityPolicy;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyReliabilitySummary;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DomainException;

final readonly class SupplyReliabilityFormula
{
    public function line(SupplyLineFact $fact, SupplyReliabilityPolicy $policy): SupplyLineMetric
    {
        $net = BigDecimal::zero();
        $onTimeReceived = BigDecimal::zero();
        $sent = false;
        $cancelled = false;
        $cancellationExcluded = false;
        $cutoff = $fact->originalPromiseAt->getTimestamp() + $policy->onTimeCutoffSeconds;
        $lastOccurredAt = null;
        $receipts = [];
        $reversedReceipts = [];
        $sourceEventIds = [];
        $ordered = BigDecimal::of($fact->orderedQuantity);
        $required = $ordered->minus($policy->quantityTolerance);
        $reachedInFull = false;
        $stabilityBroken = false;
        if (! $ordered->isPositive()) {
            throw new DomainException('Supply ordered quantity must be positive.');
        }
        if ($required->isNegative()) {
            throw new DomainException('Supply quantity tolerance cannot exceed ordered quantity.');
        }

        foreach ($fact->events as $event) {
            if ($fact->asOf !== null && $event->occurredAt > $fact->asOf) {
                throw new DomainException('Supply lifecycle event cannot be later than as_of.');
            }
            if (
                $event->unitDimension !== $fact->unitDimension
                || $event->unitCode !== $fact->unitCode
                || $event->conversionVersion !== $fact->conversionVersion
            ) {
                throw new DomainException('Supply lifecycle event unit identity must match the promise version.');
            }
            if (isset($sourceEventIds[$event->sourceEventId])) {
                throw new DomainException('Supply lifecycle source event identity must be unique.');
            }
            $sourceEventIds[$event->sourceEventId] = true;
            if ($lastOccurredAt !== null && $event->occurredAt < $lastOccurredAt) {
                throw new DomainException('Supply lifecycle events must be monotonic.');
            }
            $lastOccurredAt = $event->occurredAt;
            $quantity = BigDecimal::of($event->quantity);
            if ($event->type === 'sent') {
                $this->assertZero($quantity, $event->type);
                $sent = true;
            } elseif ($event->type === 'received') {
                if (! $sent) {
                    throw new DomainException('Supply receipt requires an earlier sent event.');
                }
                if (! $quantity->isPositive()) {
                    throw new DomainException('Receipt quantity must be positive.');
                }
                $receipts[$event->sourceEventId] = true;
                $net = $net->plus($quantity);
                if ($event->occurredAt->getTimestamp() <= $cutoff) {
                    $onTimeReceived = $onTimeReceived->plus($quantity);
                }
            } elseif (in_array($event->type, ['receipt_reversed', 'returned'], true)) {
                if (! $sent) {
                    throw new DomainException('Supply return or reversal requires an earlier sent event.');
                }
                if (! $quantity->isNegative()) {
                    throw new DomainException('Return and reversal quantities must be negative.');
                }
                if ($event->type === 'receipt_reversed'
                    && ($event->reversedEventId === null
                        || ! isset($receipts[$event->reversedEventId])
                        || isset($reversedReceipts[$event->reversedEventId]))) {
                    throw new DomainException('Receipt reversal must reference an earlier receipt.');
                }
                if ($event->type === 'receipt_reversed') {
                    $reversedReceipts[$event->reversedEventId] = true;
                }
                $net = $net->plus($quantity);
                if ($event->occurredAt->getTimestamp() <= $cutoff) {
                    $onTimeReceived = $onTimeReceived->plus($quantity);
                }
            } elseif ($event->type === 'cancelled') {
                $this->assertZero($quantity, $event->type);
                $cancelled = true;
                $cancellationExcluded = (! $sent && $policy->excludeCancellationBeforeSend)
                    || ($sent && $event->reasonCode !== null
                        && in_array($event->reasonCode, $policy->postSendCancellationExclusionReasons, true));
            } else {
                $this->assertZero($quantity, $event->type);
                if ($event->type === 'confirmed' && ! $sent) {
                    throw new DomainException('Supply confirmation requires an earlier sent event.');
                }
            }
            if ($net->isGreaterThanOrEqualTo($required)) {
                $reachedInFull = true;
            } elseif ($reachedInFull && in_array($event->type, ['receipt_reversed', 'returned'], true)) {
                $stabilityBroken = true;
            }
        }
        if ($net->isNegative() || $onTimeReceived->isNegative()) {
            throw new DomainException('Supply returns cannot exceed recorded receipts.');
        }

        $evaluationAt = $fact->asOf
            ?? (
                $lastOccurredAt !== null && $lastOccurredAt > $fact->originalPromiseAt
                    ? $lastOccurredAt
                    : $fact->originalPromiseAt
            );
        $mature = $cancelled
            || $evaluationAt->getTimestamp() >= $cutoff + $policy->maturitySeconds;
        $eligible = $mature && (! $cancelled || ! $cancellationExcluded);
        $onTime = $onTimeReceived->isGreaterThanOrEqualTo($required);
        $stableInFull = $net->isGreaterThanOrEqualTo($required) && ! $stabilityBroken;
        $inFull = $stableInFull;
        $otif = $eligible && $onTime && $inFull;
        $qualifyingQuantity = $eligible
            ? BigDecimal::max(
                BigDecimal::zero(),
                BigDecimal::min($onTimeReceived, $net, $ordered),
            )
            : BigDecimal::zero();
        $valueNumerator = null;
        $valueDenominator = null;
        if ($fact->orderedValueMinor !== null) {
            if ($fact->orderedValueMinor < 0 || $fact->currency === null || $fact->valueBasis === null) {
                throw new DomainException('Supply value OTIF basis is incomplete.');
            }
            $valueDenominator = $eligible ? $fact->orderedValueMinor : 0;
            $valueNumerator = $eligible
                ? BigDecimal::of($fact->orderedValueMinor)
                    ->multipliedBy($qualifyingQuantity)
                    ->dividedBy($ordered, 0, RoundingMode::Down)
                    ->toInt()
                : 0;
        }

        return new SupplyLineMetric(
            netReceivedQuantity: (string) $net->toScale(3, RoundingMode::Unnecessary),
            eligible: $eligible,
            onTime: $onTime,
            inFull: $inFull,
            otif: $otif,
            otifNumerator: $otif ? 1 : 0,
            eligibleDenominator: $eligible ? 1 : 0,
            mature: $mature,
            stableInFull: $stableInFull,
            quantityOtifNumerator: (string) $qualifyingQuantity->toScale(3, RoundingMode::Unnecessary),
            quantityOtifDenominator: $eligible
                ? (string) $ordered->toScale(3, RoundingMode::Unnecessary)
                : '0.000',
            valueOtifNumeratorMinor: $valueNumerator,
            valueOtifDenominatorMinor: $valueDenominator,
            valueCurrency: $fact->orderedValueMinor === null ? null : $fact->currency,
            valueBasis: $fact->orderedValueMinor === null ? null : $fact->valueBasis,
        );
    }

    /** @param iterable<SupplyLineMetric> $metrics */
    public function summarize(iterable $metrics): SupplyReliabilitySummary
    {
        $numerator = 0;
        $denominator = 0;
        $quantityNumerator = BigDecimal::zero();
        $quantityDenominator = BigDecimal::zero();
        $valueByBasis = [];
        foreach ($metrics as $metric) {
            $numerator += $metric->otifNumerator;
            $denominator += $metric->eligibleDenominator;
            $quantityNumerator = $quantityNumerator->plus($metric->quantityOtifNumerator);
            $quantityDenominator = $quantityDenominator->plus($metric->quantityOtifDenominator);
            if ($metric->valueOtifNumeratorMinor !== null && $metric->valueOtifDenominatorMinor !== null) {
                if ($metric->valueCurrency === null || $metric->valueBasis === null) {
                    throw new DomainException('Supply value OTIF metric basis is incomplete.');
                }
                $key = $metric->valueCurrency.'|'.$metric->valueBasis;
                $valueByBasis[$key] ??= [
                    'currency' => $metric->valueCurrency,
                    'value_basis' => $metric->valueBasis,
                    'numerator_minor' => 0,
                    'denominator_minor' => 0,
                ];
                $valueByBasis[$key]['numerator_minor'] += $metric->valueOtifNumeratorMinor;
                $valueByBasis[$key]['denominator_minor'] += $metric->valueOtifDenominatorMinor;
            }
        }
        ksort($valueByBasis, SORT_STRING);
        foreach ($valueByBasis as &$valueMetric) {
            $valueMetric['ratio'] = $valueMetric['denominator_minor'] === 0
                ? null
                : (string) BigDecimal::of($valueMetric['numerator_minor'])->dividedBy(
                    $valueMetric['denominator_minor'],
                    8,
                    RoundingMode::HalfUp,
                );
        }
        unset($valueMetric);
        $singleValueMetric = count($valueByBasis) === 1 ? reset($valueByBasis) : null;

        return new SupplyReliabilitySummary(
            otifNumerator: $numerator,
            eligibleDenominator: $denominator,
            otifRatio: $denominator === 0
                ? null
                : (string) BigDecimal::of($numerator)->dividedBy(
                    BigDecimal::of($denominator),
                    8,
                    RoundingMode::HalfUp,
                ),
            quantityOtifNumerator: (string) $quantityNumerator->toScale(3, RoundingMode::Unnecessary),
            quantityOtifDenominator: (string) $quantityDenominator->toScale(3, RoundingMode::Unnecessary),
            quantityOtifRatio: $quantityDenominator->isZero()
                ? null
                : (string) $quantityNumerator->dividedBy(
                    $quantityDenominator,
                    8,
                    RoundingMode::HalfUp,
                ),
            valueOtifNumeratorMinor: is_array($singleValueMetric)
                ? $singleValueMetric['numerator_minor']
                : null,
            valueOtifDenominatorMinor: is_array($singleValueMetric)
                ? $singleValueMetric['denominator_minor']
                : null,
            valueOtifRatio: is_array($singleValueMetric) ? $singleValueMetric['ratio'] : null,
            valueOtifByBasis: array_values($valueByBasis),
        );
    }

    private function assertZero(BigDecimal $quantity, string $eventType): void
    {
        if (! $quantity->isZero()) {
            throw new DomainException("{$eventType} event quantity must be zero.");
        }
    }
}
