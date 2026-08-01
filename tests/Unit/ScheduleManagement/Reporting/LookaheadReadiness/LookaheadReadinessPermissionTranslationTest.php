<?php

declare(strict_types=1);

namespace Tests\Unit\ScheduleManagement\Reporting\LookaheadReadiness;

use App\BusinessModules\Features\ScheduleManagement\Reporting\LookaheadReadiness\Services\LookaheadReadinessAbility;
use PHPUnit\Framework\TestCase;

final class LookaheadReadinessPermissionTranslationTest extends TestCase
{
    public function test_source_and_future_report_permissions_have_human_readable_russian_labels(): void
    {
        $permissions = [
            LookaheadReadinessAbility::APPROVE_SCHEDULE_REVISION,
            LookaheadReadinessAbility::PUBLISH_POLICY,
            LookaheadReadinessAbility::PUBLISH_COMMITMENT,
            LookaheadReadinessAbility::MANAGE_CONSTRAINTS,
            LookaheadReadinessAbility::APPROVE_WAIVER,
            LookaheadReadinessAbility::SEAL_EVALUATION,
            LookaheadReadinessAbility::REPORT_VIEW,
            LookaheadReadinessAbility::REPORT_EXPORT,
        ];
        $dictionary = require dirname(__DIR__, 5).'/lang/ru/permissions.php';

        foreach ($permissions as $permission) {
            $label = $dictionary['values'][$permission] ?? null;
            self::assertIsString($label, $permission);
            self::assertMatchesRegularExpression('/[А-Яа-яЁё]/u', $label, $permission);
            self::assertStringNotContainsString($permission, $label, $permission);
        }
    }
}
