<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class AiUsageData
{
    public string $immutableFingerprint;

    public function __construct(
        public AiOperationContext $context,
        public string $provider,
        public string $requestedModel,
        public string $status,
        public int $durationMs,
        public ?string $reportedModel = null,
        public string $usageStatus = 'unavailable',
        public int $inputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $outputTokens = 0,
        public int $reasoningTokens = 0,
        public int $imageCount = 0,
        public int $pageCount = 0,
        public ?string $imageDetail = null,
        public ?int $httpCode = null,
        public ?AiPriceSnapshot $priceSnapshot = null,
    ) {
        if (! in_array($status, ['succeeded', 'http_failed', 'connection_failed', 'malformed_response'], true)
            || $durationMs < 0) {
            throw new InvalidArgumentException('Invalid usage measurement.');
        }
        if (preg_match('/^[a-z0-9._-]{1,80}$/', $provider) !== 1
            || preg_match('#^[A-Za-z0-9._/-]{1,160}$#', $requestedModel) !== 1
            || ($reportedModel !== null && preg_match('#^[A-Za-z0-9._/-]{1,160}$#', $reportedModel) !== 1)
            || ! in_array($usageStatus, ['measured', 'unavailable'], true)) {
            throw new InvalidArgumentException('Invalid provider usage identifiers.');
        }
        foreach ([$inputTokens, $cachedInputTokens, $outputTokens, $reasoningTokens, $imageCount, $pageCount] as $counter) {
            if ($counter < 0) {
                throw new InvalidArgumentException('Usage counters must be nonnegative.');
            }
        }
        if ($cachedInputTokens > $inputTokens) {
            throw new InvalidArgumentException('Cached input cannot exceed input tokens.');
        }
        if (($imageCount === 0) !== ($imageDetail === null)) {
            throw new InvalidArgumentException('Image detail must match image count.');
        }
        if ($httpCode !== null && ($httpCode < 100 || $httpCode > 599)) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }

        $this->immutableFingerprint = 'sha256:'.hash('sha256', json_encode([
            'context' => get_object_vars($context), 'provider' => $provider,
            'requested_model' => $requestedModel, 'reported_model' => $reportedModel,
            'status' => $status, 'usage_status' => $usageStatus, 'http_code' => $httpCode,
            'duration_ms' => $durationMs, 'input_tokens' => $inputTokens,
            'cached_input_tokens' => $cachedInputTokens, 'output_tokens' => $outputTokens,
            'reasoning_tokens' => $reasoningTokens, 'image_count' => $imageCount,
            'image_detail' => $imageDetail, 'page_count' => $pageCount,
            'price_snapshot' => ($priceSnapshot ?? AiPriceSnapshot::fromArray([]))->toArray(),
        ], JSON_THROW_ON_ERROR));
    }
}
