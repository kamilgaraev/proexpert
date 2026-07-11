<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitContentReader;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\OcrDocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrDocumentInput;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrPageResult;
use App\BusinessModules\Addons\EstimateGeneration\DTOs\Ocr\OcrRecognitionResult;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Contracts\OcrClientInterface;
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
        $unitStore = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentProcessingUnitStore.php');

        self::assertStringContainsString("'page_id'", (string) $migration);
        self::assertStringContainsString('eg_usage_page_scope_fk', (string) $migration);
        self::assertStringContainsString("'page_id' => \$data->context->pageId", (string) $store);
        self::assertStringContainsString('->createOrFirst(', (string) $unitStore);
        self::assertStringContainsString('->whereKey($winner->getKey())->lockForUpdate()->firstOrFail()', (string) $unitStore);
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

    #[Test]
    public function page_processing_context_reaches_the_actual_ocr_operation_context(): void
    {
        $reader = new class implements DocumentUnitContentReader
        {
            public function open(DocumentUnitExecutionContext $context)
            {
                $stream = fopen('php://temp', 'w+b');
                fwrite($stream, 'image-bytes');
                rewind($stream);

                return $stream;
            }
        };
        $ocr = new class implements OcrClientInterface
        {
            public ?OcrDocumentInput $input = null;

            public function recognize(OcrDocumentInput $input): OcrRecognitionResult
            {
                $this->input = $input;

                return new OcrRecognitionResult('fixture', 'fixture-model', [new OcrPageResult(1, 'ok')]);
            }
        };
        $context = new DocumentUnitExecutionContext(
            5, 1, 2, 3, 4, DocumentUnitType::RasterImage, 1, 'source-v1', [], 'memory://document',
            'image/png', 'fixture.png', '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 1, 0, 'processing_documents', pageId: 99,
        );

        (new OcrDocumentUnitProcessor($reader, $ocr))->process($context);

        self::assertSame(99, $ocr->input?->operationContext?->pageId);
    }

    #[Test]
    public function production_store_locks_and_revalidates_claim_before_preserving_or_reserving_page(): void
    {
        $root = dirname(__DIR__, 4);
        $source = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/EloquentDocumentProcessingUnitStore.php');
        $policy = file_get_contents($root.'/app/BusinessModules/Addons/EstimateGeneration/Application/Documents/DocumentUnitPageReservationPolicy.php');

        self::assertStringContainsString('return $this->database->transaction(function () use ($claim): DocumentUnitExecutionContext', (string) $source);
        self::assertStringContainsString("->with('document.session')->lockForUpdate()->find(\$claim->unitId)", (string) $source);
        self::assertStringContainsString('DocumentProcessingUnitStatus::Running', (string) $source);
        self::assertStringContainsString("throw new DocumentUnitProcessingException('unit_claim_lost')", (string) $source);
        self::assertStringContainsString('(new DocumentUnitExecutionOwnershipGuard)->assertOwned(', (string) $source);
        self::assertStringContainsString('(new DocumentUnitPageReservationPolicy)->assertReservable(', (string) $source);
        self::assertStringContainsString("throw new DocumentUnitProcessingException('unit_page_lineage_conflict')", (string) $policy);
        self::assertStringNotContainsString('->updateOrCreate(', (string) $source);
        self::assertStringContainsString("throw new DocumentUnitProcessingException('unit_page_reservation_conflict')", (string) $policy);
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
