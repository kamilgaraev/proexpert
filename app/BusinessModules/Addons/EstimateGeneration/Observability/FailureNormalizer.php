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
    public function __construct(
        private SensitiveDiagnosticSanitizer $sanitizer = new SensitiveDiagnosticSanitizer,
        private FailureDiagnosticIdentity $diagnostics = new FailureDiagnosticIdentity,
    ) {}

    public function normalize(Throwable $error, FailureContext $context): FailureData
    {
        [$category, $code, $diagnostics] = match (true) {
            $error instanceof OcrConfigurationException => [FailureCategory::Terminal, 'ocr_not_configured', []],
            $error instanceof PipelineStageException => [$error->category, $error->safeCode, []],
            $error instanceof TypedFailureException => [$error->category, $error->safeCode, $error->safeContext],
            $error instanceof SessionAiCostLimitReached => [
                FailureCategory::Recoverable,
                'session_cost_limit_reached',
                ['reason' => $error->reason],
            ],
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

        $diagnostics = [
            ...$this->diagnostics->forThrowable($error, $this->executionBoundary($context)),
            ...($context->model === null ? [] : ['requested_model' => $context->model]),
            ...$diagnostics,
            ...($context->processingAttemptId === null ? [] : ['processing_attempt_id' => $context->processingAttemptId]),
        ];

        $safeDiagnostics = $this->sanitizer->sanitize($diagnostics);

        return new FailureData(
            $this->effectiveProviderContext($context, $safeDiagnostics),
            $category,
            $code,
            $safeDiagnostics,
        );
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
                'document_cost_limit_reached', 'session_cost_limit_reached', 'document_processing_stopped',
            ], true) => FailureCategory::UserActionRequired,
            default => FailureCategory::Terminal,
        };
    }

    private function safeKnownCode(string $candidate, string $fallback): string
    {
        return preg_match('/\A[a-z][a-z0-9_]{0,79}\z/', $candidate) === 1 ? $candidate : $fallback;
    }

    private function executionBoundary(FailureContext $context): string
    {
        return $context->operation === 'process_unit'
            ? 'document_unit_processor'
            : $this->safeKnownCode($context->operation, 'application_operation');
    }

    /** @param array<string, int|string> $diagnostics */
    private function effectiveProviderContext(FailureContext $context, array $diagnostics): FailureContext
    {
        $provider = is_string($diagnostics['provider'] ?? null) ? $diagnostics['provider'] : $context->provider;
        $model = is_string($diagnostics['requested_model'] ?? null) ? $diagnostics['requested_model'] : $context->model;
        if ($provider === $context->provider && $model === $context->model) {
            return $context;
        }

        return new FailureContext(
            organizationId: $context->organizationId,
            projectId: $context->projectId,
            sessionId: $context->sessionId,
            stage: $context->stage,
            operation: $context->operation,
            attempt: $context->attempt,
            correlationId: $context->correlationId,
            eventId: $context->eventId,
            expectedSessionStateVersion: $context->expectedSessionStateVersion,
            expectedSessionStatus: $context->expectedSessionStatus,
            documentId: $context->documentId,
            pageId: $context->pageId,
            unitId: $context->unitId,
            checkpointId: $context->checkpointId,
            usageAttemptId: $context->usageAttemptId,
            provider: $provider,
            model: $model,
            processingAttemptId: $context->processingAttemptId,
        );
    }
}
