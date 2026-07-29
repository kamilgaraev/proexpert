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
        if (! $ordered->isPositive()) {
            throw new DomainException('Supply ordered quantity must be positive.');
        }

        foreach ($fact->events as $event) {
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
        }
        if ($net->isNegative() || $onTimeReceived->isNegative()) {
            throw new DomainException('Supply returns cannot exceed recorded receipts.');
        }

        $eligible = ! $cancelled || ! $cancellationExcluded;
        $required = $ordered->minus($policy->quantityTolerance);
        if ($required->isNegative()) {
            throw new DomainException('Supply quantity tolerance cannot exceed ordered quantity.');
        }
        $onTime = $onTimeReceived->isGreaterThanOrEqualTo($required);
        $inFull = $net->isGreaterThanOrEqualTo($required);
        $otif = $eligible && $onTime && $inFull;

        return new SupplyLineMetric(
            netReceivedQuantity: (string) $net->toScale(3, RoundingMode::Unnecessary),
            eligible: $eligible,
            onTime: $onTime,
            inFull: $inFull,
            otif: $otif,
            otifNumerator: $otif ? 1 : 0,
            eligibleDenominator: $eligible ? 1 : 0,
        );
    }

    /** @param iterable<SupplyLineMetric> $metrics */
    public function summarize(iterable $metrics): SupplyReliabilitySummary
    {
        $numerator = 0;
        $denominator = 0;
        foreach ($metrics as $metric) {
            $numerator += $metric->otifNumerator;
            $denominator += $metric->eligibleDenominator;
        }

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
        );
    }

    private function assertZero(BigDecimal $quantity, string $eventType): void
    {
        if (! $quantity->isZero()) {
            throw new DomainException("{$eventType} event quantity must be zero.");
        }
    }
}
