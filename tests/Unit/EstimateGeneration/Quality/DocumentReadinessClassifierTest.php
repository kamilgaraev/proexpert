<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality;

use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationDocument;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationProcessingUnit;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\DocumentReadinessClassifier;
use Illuminate\Support\Collection;
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
        yield 'system processing failure is not a user decision' => [[
            'status' => 'failed',
            'error_code' => 'document_processing_system_failed',
            'facts_summary' => ['processing_outcome' => ['type' => 'system_failure']],
        ], false];
        yield 'temporary processing failure is not a user decision' => [[
            'status' => 'failed',
            'error_code' => 'document_processing_temporarily_unavailable',
            'facts_summary' => ['processing_outcome' => ['type' => 'temporary_failure']],
        ], false];
        yield 'legacy failed document still requires a decision' => [[
            'status' => 'failed',
            'facts_summary' => [],
        ], true];
    }

    #[Test]
    public function legacy_current_source_systemic_units_are_not_a_user_decision(): void
    {
        $document = new EstimateGenerationDocument;
        $document->forceFill([
            'id' => 168,
            'organization_id' => 38,
            'project_id' => 52,
            'session_id' => 66,
            'source_version' => 'sha256:current',
            'status' => 'needs_review',
            'facts_summary' => [],
        ]);
        $document->setRelation('processingUnits', new Collection(array_map(
            static function (int $index): EstimateGenerationProcessingUnit {
                $unit = new EstimateGenerationProcessingUnit;
                $unit->forceFill([
                    'organization_id' => 38,
                    'project_id' => 52,
                    'session_id' => 66,
                    'document_id' => 168,
                    'source_version' => 'sha256:current',
                    'status' => 'failed',
                    'output_count' => 0,
                    'failure_fingerprint' => hash('sha256', 'legacy-root'),
                ]);

                return $unit;
            }, range(1, 3)),
        ));

        self::assertFalse((new DocumentReadinessClassifier)->requiresAction($document));
    }
}
