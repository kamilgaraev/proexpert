<?php

declare(strict_types=1);

namespace Tests\Architecture\Reporting;

use App\BusinessModules\Core\Reporting\Application\Generation\ReportPermissionTranslationGenerator;
use PHPUnit\Framework\TestCase;

final class ReportPermissionTranslationGenerationTest extends TestCase
{
    public function test_generated_translation_artifact_uses_russian_group_title_and_permission_labels(): void
    {
        $artifact = ReportPermissionTranslationGenerator::fromProject(dirname(__DIR__, 3))->generate(
            ['holding_performance'],
            ['portfolio'],
            ['reports.view'],
        );

        self::assertMatchesRegularExpression('/\\p{Cyrillic}/u', $artifact['groups']['portfolio']);
        self::assertMatchesRegularExpression('/\\p{Cyrillic}/u', $artifact['titles']['holding_performance']);
        self::assertMatchesRegularExpression('/\\p{Cyrillic}/u', $artifact['permissions']['reports.view']);
    }
}
