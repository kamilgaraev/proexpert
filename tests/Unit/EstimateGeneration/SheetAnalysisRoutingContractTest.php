<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SheetAnalysisRoutingContractTest extends TestCase
{
    #[Test]
    public function classifier_preserves_closed_provider_roles_and_keeps_inferences_explicit(): void
    {
        $root = dirname(__DIR__, 3);
        $classifier = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/Understanding/SheetRoleClassifier.php');
        $validator = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Vision/ProjectSheetAnalysisValidator.php');

        self::assertStringContainsString('$declared === \'visual\'', $classifier);
        self::assertStringContainsString("'native_text_explication_marker'", $classifier);
        self::assertStringContainsString("['plan', 'section', 'elevation', 'specification', 'visual', 'unknown']", $validator);
        self::assertStringNotContainsString("'detail'", $validator);
        self::assertStringNotContainsString("'explication'", $validator);
    }

    #[Test]
    public function targeted_reanalysis_has_a_single_capped_and_auditable_route(): void
    {
        $root = dirname(__DIR__, 3);
        $processor = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/ProductionDocumentUnitProcessor.php');
        $provider = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Vision/Providers/TimewebVisionProvider.php');
        $audit = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Services/EstimateGenerationAuditService.php');

        self::assertStringContainsString('requiresTargetedReanalysis()', $processor);
        self::assertStringContainsString("'attempt_limit' => 1", $audit);
        self::assertStringContainsString("'outcome' => 'targeted_reanalysis'", $audit);
        self::assertStringContainsString("'outcome'] = 'needs_review'", $processor);
        self::assertStringContainsString('sheetRoutingLimit', $provider);
        self::assertStringContainsString('Do not expand into a second full-document inventory.', $provider);
    }
}
