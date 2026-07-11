<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DocumentReadinessClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentReadinessClassifierTest extends TestCase
{
    /** @param array<string, mixed> $attributes */
    #[Test]
    #[DataProvider('documents')]
    public function model_and_sql_contract_share_every_canonical_rule(array $attributes, bool $expected): void
    {
        $classifier = new DocumentReadinessClassifier;
        $document = new EstimateGenerationDocument;
        $document->forceFill($attributes);

        self::assertSame($expected, $classifier->requiresAction($document));
        foreach (['failed', 'needs_review', 'role_for_estimation', 'requires_manual_review'] as $rule) {
            self::assertStringContainsString($rule, $classifier->actionRequiredSql());
        }
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function documents(): iterable
    {
        yield 'ready understood' => [['status' => 'ready', 'quality_level' => 'high', 'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'primary']]], false];
        yield 'empty role' => [['status' => 'ready', 'quality_level' => 'high', 'facts_summary' => []], false];
        yield 'manual review' => [['status' => 'ready', 'quality_level' => 'high', 'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'primary', 'extracted_capabilities' => ['requires_manual_review' => true]]]], true];
        yield 'conflict alone' => [['status' => 'ready', 'quality_level' => 'high', 'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'primary'], 'conflicts' => [['code' => 'scale']]]], false];
        yield 'low quality alone' => [['status' => 'ready', 'quality_level' => 'low', 'facts_summary' => ['document_understanding' => ['role_for_estimation' => 'primary']]], false];
        yield 'pending without role' => [['status' => 'processing', 'quality_level' => null, 'facts_summary' => []], false];
        yield 'ignored' => [['status' => 'ignored', 'quality_level' => 'unusable', 'facts_summary' => []], false];
    }
}
