<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Vision\TargetedSheetRecheckScope;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseLessTestCase;

final class TargetedSheetRecheckScopeTest extends DatabaseLessTestCase
{
    #[Test]
    public function it_limits_recheck_to_one_entity_or_one_conflicting_sheet_pair(): void
    {
        $entity = TargetedSheetRecheckScope::forEntity('plan', 'sheet_role_insufficient_evidence', 'room-1', 'document:13/sheet:17');
        $pair = TargetedSheetRecheckScope::forSheetPair('section', 'sheet_role_conflict', 'document:13/sheet:17', 'document:13/sheet:18');

        self::assertSame('room-1', $entity->entityKey);
        self::assertSame(['document:13/sheet:17'], $entity->sourceSet);
        self::assertNull($pair->entityKey);
        self::assertSame(['document:13/sheet:17', 'document:13/sheet:18'], $pair->sourceSet);
    }

    #[Test]
    public function it_rejects_broad_or_sensitive_recheck_sources(): void
    {
        foreach ([
            fn () => TargetedSheetRecheckScope::forSheetPair('plan', 'sheet_role_conflict', 'document:13/sheet:17', 'document:13/sheet:18', 'document:13/sheet:19'),
            fn () => TargetedSheetRecheckScope::forEntity('plan', 'sheet_role_conflict', 'room-1', 'C:\\Users\\person\\drawing.png'),
        ] as $invalid) {
            try {
                $invalid();
                self::fail('Expected a bounded privacy-safe source set.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    #[Test]
    public function safe_reason_and_source_set_are_part_of_the_immutable_usage_record(): void
    {
        $scope = TargetedSheetRecheckScope::forSheetPair('facade', 'sheet_role_conflict', 'document:13/sheet:17', 'document:13/sheet:18');
        $usage = new AiUsageData(
            context: new AiOperationContext(
                '11111111-1111-5111-8111-111111111111', '22222222-2222-5222-8222-222222222222',
                7, 9, 11, 'understand_documents', 'vision', 2, 13, 17, 19,
            ),
            provider: 'timeweb',
            requestedModel: 'vision/model-v1',
            status: 'succeeded',
            durationMs: 10,
            requestContext: $scope->toSafeUsageContext(),
        );

        self::assertSame('sheet_role_conflict', $usage->requestContext['reason']);
        self::assertSame(['document:13/sheet:17', 'document:13/sheet:18'], $usage->requestContext['source_set']);

        $changed = new AiUsageData(
            context: $usage->context,
            provider: 'timeweb',
            requestedModel: 'vision/model-v1',
            status: 'succeeded',
            durationMs: 10,
            requestContext: TargetedSheetRecheckScope::forSheetPair(
                'facade', 'sheet_role_conflict', 'document:13/sheet:17', 'document:13/sheet:20',
            )->toSafeUsageContext(),
        );
        self::assertNotSame($usage->immutableFingerprint, $changed->immutableFingerprint);
    }

    #[Test]
    public function ledger_schema_and_store_persist_only_the_closed_safe_context(): void
    {
        $root = dirname(__DIR__, 4);
        $store = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Observability/EloquentAiUsageStore.php');
        $migration = (string) file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000100_add_request_context_to_estimate_generation_ai_usage.php');

        self::assertStringContainsString("'request_context' => json_encode(\$data->requestContext", $store);
        self::assertStringContainsString("request_context - ARRAY['contract_version','role','reason','source_set','entity_key']", $migration);
        self::assertStringContainsString("request_context->>'reason' IN ('sheet_role_conflict','sheet_role_insufficient_evidence')", $migration);
        self::assertStringContainsString('octet_length(request_context::text) <= 1024', $migration);
        self::assertStringContainsString('prompt|content|filename|path|secret|token|authorization', $migration);
    }

    #[Test]
    public function usage_dto_rejects_an_untrusted_source_path_even_if_the_array_shape_is_valid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AiUsageData(
            context: new AiOperationContext(
                '11111111-1111-5111-8111-111111111111', '22222222-2222-5222-8222-222222222222',
                7, 9, 11, 'understand_documents', 'vision', 2, 13, 17, 19,
            ),
            provider: 'timeweb',
            requestedModel: 'vision/model-v1',
            status: 'succeeded',
            durationMs: 10,
            requestContext: [
                'contract_version' => 'targeted-sheet-recheck:v1',
                'role' => 'plan',
                'reason' => 'sheet_role_conflict',
                'source_set' => ['C:\\Users\\person\\drawing.png'],
                'entity_key' => 'room-1',
            ],
        );
    }
}
