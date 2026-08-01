<?php

declare(strict_types=1);

namespace Tests\Unit\WorkforceManagement\Reporting\Capacity;

use App\BusinessModules\Features\WorkforceManagement\Reporting\Capacity\DTO\WorkforceCapacityPolicyDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorkforceCapacityPolicyDefinitionTest extends TestCase
{
    #[Test]
    public function v1_policy_pins_timezone_calendar_precedence_and_closed_vocabularies(): void
    {
        $policy = WorkforceCapacityPolicyDefinition::v1('Europe/Moscow');

        self::assertSame('workforce-capacity-policy.v1', $policy->version);
        self::assertSame('Europe/Moscow', $policy->timezone);
        self::assertSame(['schedule_day', 'weekly_pattern', 'gap'], $policy->calendarPrecedence);
        self::assertSame(['active'], $policy->assignmentStatuses);
        self::assertSame(['approved'], $policy->unavailabilityStatuses);
        self::assertContains('missing_schedule', $policy->gapCodes);
        self::assertContains('cross_scope_unavailability', $policy->gapCodes);
        self::assertSame(64, strlen($policy->hash()));
        self::assertSame($policy->hash(), WorkforceCapacityPolicyDefinition::v1('Europe/Moscow')->hash());
        self::assertNotSame($policy->hash(), WorkforceCapacityPolicyDefinition::v1('Asia/Yekaterinburg')->hash());
    }

    #[Test]
    public function invalid_or_ambiguous_timezone_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('workforce_capacity_timezone_invalid');

        WorkforceCapacityPolicyDefinition::v1('');
    }
}
