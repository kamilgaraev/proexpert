<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyLifecycleFact;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyLineFact;
use App\BusinessModules\Features\Procurement\Reporting\Supply\DTO\SupplyReliabilityPolicy;
use App\BusinessModules\Features\Procurement\Reporting\Supply\Services\SupplyReliabilityFormula;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class SupplyReliabilityFormulaTest extends TestCase
{
    public function test_return_reduces_net_receipt_and_resets_otif_stability(): void
    {
        $metric = (new SupplyReliabilityFormula)->line(
            $this->line([
                $this->event('sent', '0.000', '2026-07-01T12:00:00+03:00'),
                $this->event('received', '10.000', '2026-07-09T12:00:00+03:00'),
                $this->event('returned', '-2.000', '2026-07-12T09:00:00+03:00'),
            ]),
            new SupplyReliabilityPolicy,
        );

        self::assertSame('8.000', $metric->netReceivedQuantity);
        self::assertTrue($metric->onTime);
        self::assertFalse($metric->inFull);
        self::assertFalse($metric->otif);
    }

    public function test_reversal_before_cutoff_removes_quantity_from_on_time_receipts(): void
    {
        $metric = (new SupplyReliabilityFormula)->line(
            $this->line([
                $this->event('sent', '0', '2026-07-01T12:00:00+03:00'),
                $this->event('received', '10', '2026-07-09T12:00:00+03:00', sourceEventId: 'receipt-1'),
                $this->event('receipt_reversed', '-2', '2026-07-09T13:00:00+03:00', reversedEventId: 'receipt-1'),
            ]),
            new SupplyReliabilityPolicy,
        );

        self::assertSame('8.000', $metric->netReceivedQuantity);
        self::assertFalse($metric->onTime);
        self::assertFalse($metric->otif);
    }

    public function test_post_send_cancellation_is_failure_without_policy_exclusion(): void
    {
        $metric = (new SupplyReliabilityFormula)->line(
            $this->line([
                $this->event('sent', '0', '2026-07-01T12:00:00+03:00'),
                $this->event('cancelled', '0', '2026-07-02T12:00:00+03:00'),
            ]),
            new SupplyReliabilityPolicy,
        );

        self::assertTrue($metric->eligible);
        self::assertFalse($metric->otif);
    }

    public function test_lifecycle_events_must_be_monotonic(): void
    {
        $this->expectException(DomainException::class);

        (new SupplyReliabilityFormula)->line(
            $this->line([
                $this->event('received', '10', '2026-07-09T12:00:00+03:00'),
                $this->event('sent', '0', '2026-07-01T12:00:00+03:00'),
            ]),
            new SupplyReliabilityPolicy,
        );
    }

    public function test_summary_uses_numerator_and_denominator_sums(): void
    {
        $formula = new SupplyReliabilityFormula;
        $summary = $formula->summarize([
            $formula->line($this->line([
                $this->event('sent', '0', '2026-07-01T12:00:00+03:00'),
                $this->event('received', '10', '2026-07-09T12:00:00+03:00'),
            ]), new SupplyReliabilityPolicy),
            $formula->line($this->line([
                $this->event('cancelled', '0', '2026-06-30T12:00:00+03:00'),
            ]), new SupplyReliabilityPolicy),
            $formula->line($this->line([
                $this->event('sent', '0', '2026-07-01T12:00:00+03:00'),
            ]), new SupplyReliabilityPolicy),
        ]);

        self::assertSame(1, $summary->otifNumerator);
        self::assertSame(2, $summary->eligibleDenominator);
        self::assertSame('0.50000000', $summary->otifRatio);
    }

    private function line(array $events): SupplyLineFact
    {
        return new SupplyLineFact(
            orderedQuantity: '10.000',
            originalPromiseAt: new DateTimeImmutable('2026-07-10T18:00:00+03:00'),
            unitDimension: 'count',
            unitCode: 'piece',
            conversionVersion: 'unit-v1',
            events: $events,
        );
    }

    private function event(
        string $type,
        string $quantity,
        string $occurredAt,
        ?string $sourceEventId = null,
        ?string $reversedEventId = null,
    ): SupplyLifecycleFact {
        return new SupplyLifecycleFact(
            type: $type,
            quantity: $quantity,
            unitDimension: 'count',
            unitCode: 'piece',
            conversionVersion: 'unit-v1',
            occurredAt: new DateTimeImmutable($occurredAt),
            sourceEventId: $sourceEventId ?? $type.'-'.$occurredAt,
            reversedEventId: $reversedEventId,
        );
    }
}
