<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Execution;

use App\BusinessModules\Core\Reporting\Application\Execution\ReportProgressWritePolicy;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportProgress;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportProgressWritePolicyTest extends TestCase
{
    #[DataProvider('decisions')]
    public function test_it_persists_only_a_real_stage_after_five_seconds(
        int $persisted,
        int $current,
        string $previousAt,
        string $currentAt,
        bool $expected,
    ): void {
        self::assertSame($expected, (new ReportProgressWritePolicy)->shouldPersist(
            new ReportProgress($persisted),
            new ReportProgress($current),
            new DateTimeImmutable($previousAt),
            new DateTimeImmutable($currentAt),
        ));
    }

    public static function decisions(): iterable
    {
        yield 'one percent after five seconds' => [10, 11, '2026-07-26T10:00:00Z', '2026-07-26T10:00:05Z', true];
        yield 'same stage' => [10, 10, '2026-07-26T10:00:00Z', '2026-07-26T10:00:10Z', false];
        yield 'too soon' => [10, 20, '2026-07-26T10:00:00Z', '2026-07-26T10:00:04.999999Z', false];
        yield 'terminal stage is sealed separately' => [99, 100, '2026-07-26T10:00:00Z', '2026-07-26T10:00:10Z', false];
    }
}
