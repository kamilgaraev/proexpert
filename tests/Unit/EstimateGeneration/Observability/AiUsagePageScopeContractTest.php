<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiUsagePageScopeContractTest extends TestCase
{
    #[Test]
    public function page_identity_is_part_of_the_immutable_usage_fingerprint(): void
    {
        $first = $this->usage(10);
        $second = $this->usage(11);

        self::assertSame(10, $first->context->pageId);
        self::assertNotSame($first->immutableFingerprint, $second->immutableFingerprint);
    }

    #[Test]
    public function migration_and_store_persist_tenant_bound_page_identity(): void
    {
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_000400_create_estimate_generation_ai_usage_table.php');
        $store = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Observability/EloquentAiUsageStore.php');

        self::assertStringContainsString("'page_id'", (string) $migration);
        self::assertStringContainsString('eg_usage_page_scope_fk', (string) $migration);
        self::assertStringContainsString("'page_id' => \$data->context->pageId", (string) $store);
    }

    #[Test]
    public function optional_scope_ids_must_be_null_or_positive_and_stage_matches_operation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AiOperationContext(
            '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
            1, 2, 3, 'match_normatives', 'ocr', 1, documentId: 0,
        );
    }

    private function usage(int $pageId): AiUsageData
    {
        return new AiUsageData(
            context: new AiOperationContext(
                correlationId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
                attemptId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
                organizationId: 1,
                projectId: 2,
                sessionId: 3,
                stage: 'understand_documents',
                operation: 'ocr',
                attemptOrdinal: 1,
                documentId: 4,
                pageId: $pageId,
                unitId: 5,
            ),
            provider: 'timeweb',
            requestedModel: 'model-a',
            status: 'succeeded',
            durationMs: 1,
        );
    }
}
