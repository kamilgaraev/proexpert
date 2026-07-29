<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\Models\PerformanceActCompletedWork;
use App\Models\PerformanceActLine;
use App\Services\CompletedWork\Reporting\AcceptedProduction\Services\ApprovedAcceptanceRateResolver;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ApprovedAcceptanceRateResolverTest extends TestCase
{
    #[Test]
    public function typed_line_pins_its_explicit_approved_rate_and_currency(): void
    {
        $line = new PerformanceActLine;
        $line->forceFill([
            'unit_price' => '1250.45',
            'currency' => 'RUB',
        ]);

        $rate = (new ApprovedAcceptanceRateResolver)->fromLine($line);

        self::assertSame(125_045, $rate->minor);
        self::assertSame('RUB', $rate->currency);
        self::assertSame('performance_act_line.unit_price@performance_act_line.currency', $rate->source);
    }

    #[Test]
    public function pivot_rate_is_accepted_only_when_minor_unit_division_is_exact(): void
    {
        $pivot = new PerformanceActCompletedWork;
        $pivot->forceFill([
            'included_quantity' => '2.500',
            'included_amount' => '25.00',
            'currency' => 'RUB',
        ]);

        $rate = (new ApprovedAcceptanceRateResolver)->fromPivot($pivot);

        self::assertSame(1_000, $rate->minor);
        self::assertSame('RUB', $rate->currency);

        $pivot->forceFill(['included_amount' => '25.01']);
        $this->expectException(InvalidArgumentException::class);
        (new ApprovedAcceptanceRateResolver)->fromPivot($pivot);
    }

    #[Test]
    public function act_currency_is_pinned_when_source_line_has_no_own_currency(): void
    {
        $line = new PerformanceActLine;
        $line->forceFill(['unit_price' => '100.00']);

        $rate = (new ApprovedAcceptanceRateResolver)->fromLine($line, 'RUB');

        self::assertSame('RUB', $rate->currency);
        self::assertSame('performance_act_line.unit_price@contract_performance_act.currency', $rate->source);
    }
}
