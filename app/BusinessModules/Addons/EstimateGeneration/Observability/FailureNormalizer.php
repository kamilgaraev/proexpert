<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentManifestNeedsReview;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessingException;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineStageException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrConfigurationException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\Exceptions\OcrProviderException;
use Throwable;

final readonly class FailureNormalizer
{
    public function __construct(private SensitiveDiagnosticSanitizer $sanitizer = new SensitiveDiagnosticSanitizer) {}

    public function normalize(Throwable $error, FailureContext $context): FailureData
    {
        [$category, $code, $diagnostics] = match (true) {
            $error instanceof OcrConfigurationException => [FailureCategory::Terminal, 'ocr_not_configured', []],
            $error instanceof PipelineStageException => [$error->category, $error->safeCode, []],
            $error instanceof TypedFailureException => [$error->category, $error->safeCode, $error->safeContext],
            $error instanceof OcrProviderException => $this->ocrProvider($error),
            $error instanceof RerankWireException => [
                $error->attemptStatus === 'malformed_response' ? FailureCategory::Terminal : FailureCategory::Recoverable,
                $error->attemptStatus === 'malformed_response' ? 'reranker_response_invalid' : 'reranker_unavailable',
                ['http_code' => $error->httpCode, 'status' => $error->attemptStatus],
            ],
            $error instanceof DocumentManifestNeedsReview => [FailureCategory::UserActionRequired, 'document_manifest_review_required', []],
            $error instanceof DocumentUnitProcessingException => [
                $this->unitCategory($error->safeCode),
                $this->safeKnownCode($error->safeCode, 'unit_processing_failed'),
                ['safe_code' => $this->safeKnownCode($error->safeCode, 'unit_processing_failed')],
            ],
            $error instanceof StaleEstimateGenerationState => [FailureCategory::Recoverable, 'stale_session_state', []],
            default => [FailureCategory::Terminal, 'unexpected_internal_failure', []],
        };

        return new FailureData($context, $category, $code, $this->sanitizer->sanitize($diagnostics));
    }

    /** @return array{FailureCategory, string, array<string, mixed>} */
    private function ocrProvider(OcrProviderException $error): array
    {
        $status = $error->statusCode;
        $category = match (true) {
            $status === 408, $status === 429, $status !== null && $status >= 500 => FailureCategory::Recoverable,
            $status !== null && in_array($status, [400, 404, 413, 415, 422], true) => FailureCategory::UserActionRequired,
            default => FailureCategory::Terminal,
        };
        $code = match ($category) {
            FailureCategory::Recoverable => 'ocr_provider_unavailable',
            FailureCategory::UserActionRequired => 'document_input_invalid',
            FailureCategory::Terminal => 'ocr_provider_rejected',
        };

        return [$category, $code, [
            'provider_code' => $error->providerCode,
            'http_code' => $status,
            'http_class' => $status === null ? null : intdiv($status, 100).'xx',
        ]];
    }

    private function unitCategory(string $code): FailureCategory
    {
        return match (true) {
            in_array($code, ['unit_claim_lost', 'unit_page_reservation_conflict'], true) => FailureCategory::Recoverable,
            in_array($code, [
                'document_input_invalid', 'drawing_geometry_unreadable', 'unit_page_lineage_conflict',
                'cad_geometry_processor_required', 'unit_artifact_manifest_required', 'unit_content_empty',
                'unit_recognition_empty',
            ], true) => FailureCategory::UserActionRequired,
            default => FailureCategory::Terminal,
        };
    }

    private function safeKnownCode(string $candidate, string $fallback): string
    {
        return preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $candidate) === 1 ? $candidate : $fallback;
    }
}
