<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Illuminate\Database\Connection;

final readonly class EloquentAiUsageStore implements AiUsageStore
{
    public function __construct(
        private AiCostCalculator $calculator,
        private Connection $database,
        private ?AiBudgetGuard $budgetGuard = null,
    ) {}

    public function record(AiUsageData $data): void
    {
        $cost = $data->usageStatus === 'measured' ? $this->calculator->calculate(
            $data->inputTokens,
            $data->cachedInputTokens,
            $data->outputTokens,
            $data->reasoningTokens,
            $data->imageCount,
            $data->pageCount,
            ($data->priceSnapshot ?? AiPriceSnapshot::fromArray([]))->toArray(),
        ) : new AiCost(null, null, 'unavailable');
        $attributes = [
            'attempt_id' => $data->context->attemptId,
            'correlation_id' => $data->context->correlationId,
            'immutable_fingerprint' => $data->immutableFingerprint,
            'organization_id' => $data->context->organizationId,
            'project_id' => $data->context->projectId,
            'session_id' => $data->context->sessionId,
            'document_id' => $data->context->documentId,
            'page_id' => $data->context->pageId,
            'unit_id' => $data->context->unitId,
            'stage' => $data->context->stage,
            'operation' => $data->context->operation,
            'attempt_ordinal' => $data->context->attemptOrdinal,
            'provider' => $data->provider,
            'requested_model' => $data->requestedModel,
            'reported_model' => $data->reportedModel,
            'usage_status' => $data->usageStatus,
            'status' => $data->status,
            'http_code' => $data->httpCode,
            'input_tokens' => $data->inputTokens,
            'cached_input_tokens' => $data->cachedInputTokens,
            'output_tokens' => $data->outputTokens,
            'reasoning_tokens' => $data->reasoningTokens,
            'image_count' => $data->imageCount,
            'image_detail' => $data->imageDetail,
            'page_count' => $data->pageCount,
            'duration_ms' => $data->durationMs,
            'price_snapshot' => json_encode(
                ($data->priceSnapshot ?? AiPriceSnapshot::fromArray([]))->toArray(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ),
            'cost_amount' => $cost->amount,
            'currency' => $cost->currency,
            'pricing_status' => $cost->pricingStatus,
            'created_at' => now(),
        ];

        $this->database->table('estimate_generation_ai_usage')->insertOrIgnore($attributes);
        $existing = $this->database->table('estimate_generation_ai_usage')->where('attempt_id', $data->context->attemptId)->first();
        if ($existing === null || ! hash_equals((string) $existing->immutable_fingerprint, $data->immutableFingerprint)) {
            throw new UsageInvariantViolation('AI usage attempt collision.');
        }
        if ($this->budgetGuard !== null && $cost->pricingStatus === 'available') {
            $this->budgetGuard->settle($data->context->attemptId, $cost);
        }
    }
}
