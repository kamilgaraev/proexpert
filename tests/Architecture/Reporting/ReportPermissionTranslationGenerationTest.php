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

    public function test_translation_source_has_exactly_seven_groups_and_twenty_eight_report_titles(): void
    {
        $source = require dirname(__DIR__, 3).'/lang/ru/reports.php';
        $artifact = ReportPermissionTranslationGenerator::fromProject(dirname(__DIR__, 3))->generate(
            array_keys($source['catalog']),
            array_keys($source['catalog_groups']),
            [],
        );

        self::assertCount(7, $source['catalog_groups']);
        self::assertCount(28, $source['catalog']);
        self::assertSame(array_keys($source['catalog_groups']), array_keys($artifact['groups']));
        self::assertSame(array_keys($source['catalog']), array_keys($artifact['titles']));
        self::assertSame([], $artifact['permissions']);
    }
}
