<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LookaheadPolicyVersionTest extends TestCase
{
    #[Test]
    public function effective_interval_is_inclusive_and_hash_is_mandatory(): void
    {
        $policy = new LookaheadReadinessPolicyVersion(
            version: 2,
            organizationId: 7,
            horizonDays: 21,
            eligibleTaskStatuses: ['pending', 'in_progress'],
            mandatoryConstraintTypes: ['material', 'rfi'],
            hardSeverities: ['critical'],
            waiverEvidenceRequired: true,
            effectiveFrom: new DateTimeImmutable('2026-07-01T00:00:00+03:00'),
            effectiveUntil: new DateTimeImmutable('2026-07-31T23:59:59+03:00'),
            timezone: 'Europe/Moscow',
            sourceHash: str_repeat('a', 64),
        );

        self::assertTrue($policy->appliesAt(new DateTimeImmutable('2026-07-01T00:00:00+03:00')));
        self::assertTrue($policy->appliesAt(new DateTimeImmutable('2026-07-31T23:59:59+03:00')));
        self::assertFalse($policy->appliesAt(new DateTimeImmutable('2026-08-01T00:00:00+03:00')));

        $this->expectException(InvalidArgumentException::class);
        new LookaheadReadinessPolicyVersion(
            1,
            7,
            21,
            ['pending'],
            ['material'],
            ['critical'],
            true,
            new DateTimeImmutable('2026-07-01'),
            null,
            'Europe/Moscow',
            'not-a-sha256',
        );
    }
}
