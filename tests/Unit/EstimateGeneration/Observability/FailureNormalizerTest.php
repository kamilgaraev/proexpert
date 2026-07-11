<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureCategory;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureNormalizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use App\BusinessModules\Addons\EstimateGeneration\Observability\TypedFailureException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FailureNormalizerTest extends TestCase
{
    #[Test]
    #[DataProvider('knownFailures')]
    public function it_maps_known_failures_to_closed_safe_contract(
        \Throwable $error,
        FailureCategory $category,
        string $code,
    ): void {
        $failure = (new FailureNormalizer)->normalize($error, $this->context());

        self::assertSame($category, $failure->category);
        self::assertSame($code, $failure->code);
    }

    /** @return iterable<string, array{\Throwable, FailureCategory, string}> */
    public static function knownFailures(): iterable
    {
        yield 'provider timeout' => [
            new OcrProviderException('secret provider message', 503, 'timeout'),
            FailureCategory::Recoverable,
            'ocr_provider_unavailable',
        ];
        yield 'provider validation' => [
            new OcrProviderException('secret provider message', 422, 'bad_image'),
            FailureCategory::UserActionRequired,
            'document_input_invalid',
        ];
        yield 'configuration' => [
            new OcrConfigurationException('secret.key'),
            FailureCategory::Terminal,
            'ocr_not_configured',
        ];
        yield 'reranker transport' => [
            new RerankWireException('connection_failed'),
            FailureCategory::Recoverable,
            'reranker_unavailable',
        ];
        yield 'unit input' => [
            new DocumentUnitProcessingException('unit_output_identity_mismatch'),
            FailureCategory::Terminal,
            'unit_output_identity_mismatch',
        ];
        yield 'claim loss' => [
            new DocumentUnitProcessingException('unit_claim_lost'),
            FailureCategory::Recoverable,
            'unit_claim_lost',
        ];
        yield 'lineage conflict' => [
            new DocumentUnitProcessingException('unit_page_lineage_conflict'),
            FailureCategory::UserActionRequired,
            'unit_page_lineage_conflict',
        ];
        yield 'pipeline claim' => [
            new PipelineStageException(FailureCategory::Recoverable, 'pipeline_claim_lost'),
            FailureCategory::Recoverable,
            'pipeline_claim_lost',
        ];
        yield 'storage' => [
            new TypedFailureException(FailureCategory::Recoverable, 'document_storage_unavailable'),
            FailureCategory::Recoverable,
            'document_storage_unavailable',
        ];
    }

    #[Test]
    public function unknown_throwable_is_terminal_and_never_leaks_message_or_class(): void
    {
        $failure = (new FailureNormalizer)->normalize(
            new RuntimeException('Bearer secret-personal-document-text'),
            $this->context(),
        );

        self::assertSame(FailureCategory::Terminal, $failure->category);
        self::assertSame('unexpected_internal_failure', $failure->code);
        self::assertStringNotContainsString('secret', json_encode($failure->safeContext, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString(RuntimeException::class, $failure->fingerprint);
    }

    #[Test]
    public function fingerprint_ignores_message_but_separates_tenant_stage_and_code(): void
    {
        $normalizer = new FailureNormalizer;
        $first = $normalizer->normalize(new RuntimeException('first secret'), $this->context());
        $same = $normalizer->normalize(new RuntimeException('second secret'), $this->context());
        $otherTenant = $normalizer->normalize(new RuntimeException('first secret'), $this->context(organizationId: 2));
        $otherStage = $normalizer->normalize(new RuntimeException('first secret'), $this->context(stage: ProcessingStage::BuildDraft));

        self::assertSame($first->fingerprint, $same->fingerprint);
        self::assertNotSame($first->fingerprint, $otherTenant->fingerprint);
        self::assertNotSame($first->fingerprint, $otherStage->fingerprint);
    }

    private function context(
        int $organizationId = 1,
        ProcessingStage $stage = ProcessingStage::UnderstandDocuments,
    ): FailureContext {
        return new FailureContext(
            organizationId: $organizationId,
            projectId: 10,
            sessionId: 100,
            stage: $stage,
            operation: 'process_unit',
            attempt: 1,
            correlationId: '018f4a20-3f4c-7a11-8a22-123456789abc',
            documentId: 1000,
            unitId: 1001,
        );
    }
}
