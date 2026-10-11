<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Pipeline\CanonicalPipelineJson;
use App\Models\Credits\AICreditReservation;
use App\Services\Credits\AICreditService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;

/** Transfers the immutable physical measurement to the existing wallet in the usage transaction. */
final readonly class EstimatorCreditUsageTransfer
{
    public function __construct(private Connection $database, private AICreditService $credits) {}

    public function record(AiUsageData $data, AiCost $cost): void
    {
        $context = $data->context;
        if ($context->creditOperationId === null) {
            return;
        }
        if ($this->database->transactionLevel() < 1) {
            throw new UsageInvariantViolation('estimator_credit_usage_requires_transaction');
        }
        $operation = $this->database->table('estimate_generation_credit_operations')
            ->where('operation_id', $context->creditOperationId)->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)->where('session_id', $context->sessionId)->lockForUpdate()->first();
        $attempt = $this->database->table('estimate_generation_credit_attempts')->where('attempt_id', $context->attemptId)
            ->where('operation_id', $context->creditOperationId)->where('organization_id', $context->organizationId)
            ->where('project_id', $context->projectId)->where('session_id', $context->sessionId)->lockForUpdate()->first();
        if ($operation === null || $attempt === null || $operation->reservation_id !== $attempt->reservation_id
            || $attempt->role !== $context->operation) {
            throw new UsageInvariantViolation('estimator_credit_usage_scope_mismatch');
        }
        $identity = json_decode($operation->credit_request, true, 64, JSON_THROW_ON_ERROR);
        if ($data->provider !== ($identity['provider'] ?? null) || $data->requestedModel !== ($identity['model'] ?? null)
            || CanonicalPipelineJson::encode($data->priceSnapshot?->toArray() ?? [])
                !== CanonicalPipelineJson::encode($identity['supplier_tariff'] ?? [])) {
            throw new UsageInvariantViolation('estimator_credit_usage_tariff_mismatch');
        }
        $fingerprint = substr($data->immutableFingerprint, 7);
        if ($attempt->usage_fingerprint !== null && ! hash_equals((string) $attempt->usage_fingerprint, $fingerprint)) {
            throw new UsageInvariantViolation('estimator_credit_usage_identity_mismatch');
        }
        $reservation = AICreditReservation::query()->whereKey($attempt->reservation_id)
            ->where('organization_id', $context->organizationId)->where('user_id', $operation->actor_user_id)
            ->where('ai_credit_quote_id', $operation->quote_id)->first();
        if ($reservation === null) {
            throw new UsageInvariantViolation('estimator_credit_usage_reservation_missing');
        }
        $available = $cost->pricingStatus === 'available' && $cost->currency === 'RUB' && $cost->amount !== null;
        $microRub = $available ? BigDecimal::of($cost->amount)->multipliedBy('1000000')->toScale(0, RoundingMode::Ceiling)->toInt() : 0;
        $this->credits->recordProviderCost($reservation, $microRub, $data->provider, $data->requestedModel, $context->operation, [
            'usage_key' => $context->attemptId, 'provider_usage_available' => $available,
            'estimator_operation_id' => $context->creditOperationId, 'estimator_usage_fingerprint' => $fingerprint,
            'estimator_tariff' => $identity['supplier_tariff'], 'reported_model' => $data->reportedModel,
            'input_tokens' => $data->inputTokens, 'cached_input_tokens' => $data->cachedInputTokens,
            'output_tokens' => $data->outputTokens, 'reasoning_tokens' => $data->reasoningTokens,
            'image_count' => $data->imageCount, 'page_count' => $data->pageCount,
        ], $data->status === 'succeeded' && $available);
        $this->database->table('estimate_generation_credit_attempts')->where('attempt_id', $context->attemptId)
            ->update(['status' => $available ? 'recorded' : 'unavailable', 'usage_fingerprint' => $fingerprint,
                'usage_transferred_at' => now(), 'updated_at' => now()]);
        // A late response records supplier expense. It never resumes a cancelled operation.
    }
}
