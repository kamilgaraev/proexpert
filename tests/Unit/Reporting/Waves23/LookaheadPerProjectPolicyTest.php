<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting\Waves23;

use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicySet;
use App\BusinessModules\Features\ScheduleManagement\Reporting\Lookahead\Models\LookaheadReadinessPolicyVersion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LookaheadPerProjectPolicyTest extends TestCase
{
    #[Test]
    public function every_project_resolves_its_exact_policy_before_organization_default(): void
    {
        $default = $this->policy(1, null, 14, 'a');
        $projectSeven = $this->policy(2, 7, 21, 'b');
        $set = new LookaheadReadinessPolicySet([$default, $projectSeven], [7, 8]);

        self::assertSame(21, $set->forProject(7)->horizonDays);
        self::assertSame(14, $set->forProject(8)->horizonDays);
        self::assertCount(2, $set->all());
    }

    #[Test]
    public function missing_project_policy_and_default_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LookaheadReadinessPolicySet(
            [$this->policy(2, 7, 21, 'b')],
            [7, 8],
        );
    }

    private function policy(int $version, ?int $projectId, int $horizon, string $hash): LookaheadReadinessPolicyVersion
    {
        return new LookaheadReadinessPolicyVersion(
            version: $version,
            organizationId: 3,
            horizonDays: $horizon,
            eligibleTaskStatuses: ['pending'],
            mandatoryConstraintTypes: ['rfi'],
            hardSeverities: ['critical'],
            waiverEvidenceRequired: true,
            effectiveFrom: new DateTimeImmutable('2026-07-01T00:00:00+03:00'),
            effectiveUntil: null,
            timezone: 'Europe/Moscow',
            sourceHash: str_repeat($hash, 64),
            projectId: $projectId,
            policyId: $version,
        );
    }
}
