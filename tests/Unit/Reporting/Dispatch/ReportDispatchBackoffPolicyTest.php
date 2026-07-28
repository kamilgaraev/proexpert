<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Dispatch;

use App\BusinessModules\Core\Reporting\Application\Dispatch\ReportDispatchBackoffPolicy;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportDispatchBackoffPolicyTest extends TestCase
{
    #[DataProvider('attempts')]
    public function test_uses_capped_exponential_delay_for_every_supported_attempt(int $attempt, int $delay): void
    {
        $occurredAt = new DateTimeImmutable('2026-07-28T12:00:00.987654+03:00');

        $next = (new ReportDispatchBackoffPolicy())->nextAttemptAt($attempt, $occurredAt);

        self::assertSame(
            (new DateTimeImmutable('2026-07-28T09:00:00.000000Z'))
                ->modify("+{$delay} seconds")
                ->format('Y-m-d\TH:i:s.u\Z'),
            $next->format('Y-m-d\TH:i:s.u\Z'),
        );
        self::assertSame('UTC', $next->getTimezone()->getName());
    }

    public static function attempts(): iterable
    {
        foreach ([15, 30, 60, 120, 240, 480, 960, 1920, 3600, 3600, 3600, 3600] as $index => $delay) {
            yield 'attempt '.($index + 1) => [$index + 1, $delay];
        }
    }

    #[DataProvider('invalidAttempts')]
    public function test_rejects_attempt_outside_closed_range(int $attempt): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ReportDispatchBackoffPolicy())->nextAttemptAt(
            $attempt,
            new DateTimeImmutable('2026-07-28T00:00:00Z', new DateTimeZone('UTC')),
        );
    }

    public static function invalidAttempts(): iterable
    {
        yield 'zero' => [0];
        yield 'thirteen' => [13];
    }
}
