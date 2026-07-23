<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompletenessArbiterUsageContractTest extends TestCase
{
    #[Test]
    public function it_allows_the_validate_draft_completeness_operation_and_declares_it_in_the_migration(): void
    {
        $context = new AiOperationContext(
            '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
            1,
            2,
            3,
            'validate_draft',
            'completeness_review',
            1,
        );
        $root = dirname(__DIR__, 4);
        $migration = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_22_000100_extend_ai_usage_for_completeness_arbiter.php');

        self::assertSame('validate_draft', $context->stage);
        self::assertSame('completeness_review', $context->operation);
        self::assertStringContainsString("'validate_draft'", (string) $migration);
        self::assertStringContainsString("'completeness_review'", (string) $migration);
    }
}
