<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Pipeline;

use PHPUnit\Framework\TestCase;

final class DraftTitleContractTest extends TestCase
{
    public function test_build_draft_has_no_english_hardcoded_title_and_uses_domain_translation(): void
    {
        $root = dirname(__DIR__, 4);
        $stage = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Pipeline/Stages/BuildDraftStage.php');
        $translations = file_get_contents($root.'/lang/ru/estimate_generation.php');

        self::assertIsString($stage);
        self::assertStringNotContainsString('AI draft estimate', $stage);
        self::assertStringContainsString("trans_message('estimate_generation.draft_default_title')", $stage);
        self::assertIsString($translations);
        self::assertStringContainsString("'draft_default_title'", $translations);
    }
}
