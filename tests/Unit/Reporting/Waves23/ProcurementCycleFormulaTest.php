<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementCyclePolicy;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessEvent;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\DTO\ProcurementProcessTimeline;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Exceptions\NonMonotonicProcurementTimeline;
use App\BusinessModules\Features\Procurement\Reporting\Cycle\Services\ProcurementCycleFormula;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class ProcurementCycleFormulaTest extends TestCase
{
    public function test_process_timestamps_must_be_monotonic_for_one_request_line(): void
    {
        $timeline = ProcurementProcessTimeline::fromEvents([
            $this->event('request_created', '2026-07-10T10:00:00+03:00'),
            $this->event('award_decided', '2026-07-09T10:00:00+03:00'),
        ]);

        $this->expectException(NonMonotonicProcurementTimeline::class);

        (new ProcurementCycleFormula)->calculate($timeline, $this->policy('2026-07-10T18:00:00+03:00'));
    }

    public function test_closed_cycle_stops_at_terminal_event_and_uses_business_seconds(): void
    {
        $timeline = ProcurementProcessTimeline::fromEvents([
            $this->event('request_created', '2026-07-10T17:00:00+03:00'),
            $this->event('request_approved', '2026-07-13T10:00:00+03:00'),
            $this->event('fully_received', '2026-07-13T12:00:00+03:00'),
        ]);

        $metric = (new ProcurementCycleFormula)->calculate(
            $timeline,
            $this->policy('2026-07-20T18:00:00+03:00'),
        );

        self::assertSame([
            'request_created' => 7_200,
            'request_approved' => 7_200,
            'fully_received' => 0,
        ], $metric->stageDurationSeconds);
        self::assertSame(14_400, $metric->totalDurationSeconds);
        self::assertSame(2, $metric->slaNumerator);
        self::assertSame(2, $metric->slaDenominator);
        self::assertTrue($metric->closed);
    }

    public function test_open_stage_age_is_measured_to_as_of_without_counting_terminal_stage(): void
    {
        $metric = (new ProcurementCycleFormula)->calculate(
            ProcurementProcessTimeline::fromEvents([
                $this->event('request_created', '2026-07-13T09:00:00+03:00'),
                $this->event('request_approved', '2026-07-13T11:00:00+03:00'),
            ]),
            $this->policy('2026-07-13T15:00:00+03:00'),
        );

        self::assertSame(7_200, $metric->stageDurationSeconds['request_created']);
        self::assertSame(14_400, $metric->stageDurationSeconds['request_approved']);
        self::assertSame(21_600, $metric->totalDurationSeconds);
        self::assertFalse($metric->closed);
    }

    public function test_process_requires_start_and_rejects_events_after_terminal_outcome(): void
    {
        $this->expectException(DomainException::class);

        (new ProcurementCycleFormula)->calculate(
            ProcurementProcessTimeline::fromEvents([
                $this->event('request_created', '2026-07-13T09:00:00+03:00'),
                $this->event('cancelled', '2026-07-13T10:00:00+03:00'),
                $this->event('order_sent', '2026-07-13T11:00:00+03:00'),
            ]),
            $this->policy('2026-07-13T15:00:00+03:00'),
        );
    }

    public function test_outcome_cohort_is_mature_only_after_policy_window(): void
    {
        $metric = (new ProcurementCycleFormula)->calculate(
            ProcurementProcessTimeline::fromEvents([
                $this->event('request_created', '2026-07-13T09:00:00+03:00'),
                $this->event('fully_received', '2026-07-13T10:00:00+03:00'),
            ]),
            new ProcurementCyclePolicy(
                new DateTimeImmutable('2026-07-13T10:59:59+03:00'),
                [],
                'Europe/Moscow',
                [1, 2, 3, 4, 5],
                '09:00:00',
                '18:00:00',
                3600,
            ),
        );

        self::assertFalse($metric->mature);
        self::assertSame('fully_received', $metric->outcomeCode);
        self::assertSame('2026-07-13T10:00:00+03:00', $metric->outcomeAt?->format(DATE_ATOM));
    }

    private function event(string $code, string $occurredAt): ProcurementProcessEvent
    {
        return new ProcurementProcessEvent($code, new DateTimeImmutable($occurredAt));
    }

    private function policy(string $asOf): ProcurementCyclePolicy
    {
        return new ProcurementCyclePolicy(
            asOf: new DateTimeImmutable($asOf),
            stageSlaSeconds: [
                'request_created' => 7_200,
                'request_approved' => 14_400,
            ],
            timezone: 'Europe/Moscow',
            businessWeekdays: [1, 2, 3, 4, 5],
            businessDayStart: '09:00:00',
            businessDayEnd: '18:00:00',
        );
    }
}
