<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\DTO\ProjectControlSourceIdentity;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ProjectControlSourceContractTest extends TestCase
{
    public function test_source_identity_pins_every_evm_basis(): void
    {
        $identity = new ProjectControlSourceIdentity(
            organizationId: 10,
            projectId: 20,
            scheduleId: 30,
            baselineVersion: 4,
            statusDate: new DateTimeImmutable('2026-07-29'),
            wipVersion: 'wip-7',
            progressWatermark: 'progress-101',
            actualCostWatermark: 'accrual-88',
            sourceHash: str_repeat('a', 64),
        );

        self::assertSame(
            [10, 20, 30, 4, '2026-07-29', 'wip-7', 'progress-101', 'accrual-88', str_repeat('a', 64)],
            $identity->canonicalIdentity(),
        );
    }

    public function test_source_identity_rejects_unpinned_basis(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProjectControlSourceIdentity(
            10,
            20,
            30,
            4,
            new DateTimeImmutable('2026-07-29'),
            '',
            'progress-101',
            'accrual-88',
            str_repeat('a', 64),
        );
    }
}
