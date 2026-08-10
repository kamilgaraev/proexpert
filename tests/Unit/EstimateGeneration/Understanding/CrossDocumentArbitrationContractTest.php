<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Understanding;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CrossDocumentArbitrationContractTest extends TestCase
{
    #[Test]
    public function production_arbitration_reuses_attempt_aware_usage_accounting_and_a_deterministic_identity(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Understanding/AttemptAwareCrossDocumentFactArbitrator.php'
        );

        self::assertStringContainsString('AttemptAwareNormativeLlmClient', $source);
        self::assertStringContainsString("'candidate_set_hash' => \$operationIdentity", $source);
        self::assertStringContainsString("'work_item_key' => 'cross-document-fact-link'", $source);
        self::assertStringContainsString("['suggested', 'unresolved']", $source);
        self::assertStringNotContainsString("'status' => 'confirmed'", $source);
    }

    #[Test]
    public function human_questions_use_the_standard_translation_boundary(): void
    {
        $resolver = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Application/Understanding/TargetedConflictResolver.php'
        );
        $translations = (string) file_get_contents(dirname(__DIR__, 4).'/lang/ru/estimate_generation.php');

        self::assertStringContainsString('trans_message($key, $replace)', $resolver);
        self::assertStringContainsString("'conflict_question'", $translations);
        self::assertStringContainsString("'conflict_option'", $translations);
        self::assertStringContainsString("'insufficient_evidence'", $translations);
    }
}
