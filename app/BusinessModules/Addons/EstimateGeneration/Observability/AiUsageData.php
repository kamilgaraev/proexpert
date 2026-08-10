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
        /** @var array<string, mixed> */
        public array $requestContext = [],
    ) {
        if (! in_array($status, ['succeeded', 'http_failed', 'connection_failed', 'malformed_response', 'ambiguous'], true)
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
        if ($usageStatus === 'unavailable'
            && ($inputTokens !== 0 || $cachedInputTokens !== 0 || $outputTokens !== 0 || $reasoningTokens !== 0)) {
            throw new InvalidArgumentException('Unavailable usage cannot contain token measurements.');
        }
        if (($imageCount === 0) !== ($imageDetail === null)) {
            throw new InvalidArgumentException('Image detail must match image count.');
        }
        if ($httpCode !== null && ($httpCode < 100 || $httpCode > 599)) {
            throw new InvalidArgumentException('Invalid HTTP status.');
        }
        if ($status === 'ambiguous' && ($usageStatus !== 'unavailable'
            || ($httpCode !== null && ($httpCode < 200 || $httpCode > 299)))) {
            throw new InvalidArgumentException('Ambiguous usage must remain unmeasured.');
        }
        if (! self::validRequestContext($requestContext)) {
            throw new InvalidArgumentException('Invalid usage request context.');
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
            'request_context' => $requestContext,
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $context */
    private static function validRequestContext(array $context): bool
    {
        if ($context === []) {
            return true;
        }

        if (count($context) !== 5
            || array_diff(array_keys($context), ['contract_version', 'role', 'reason', 'source_set', 'entity_key']) !== []
        ) {
            return false;
        }
        if (($context['contract_version'] ?? null) !== 'targeted-sheet-recheck:v1'
            || ! in_array($context['role'] ?? null, ['plan', 'section', 'facade', 'explication', 'specification', 'unknown'], true)
            || ! in_array($context['reason'] ?? null, ['sheet_role_conflict', 'sheet_role_insufficient_evidence'], true)
            || ! is_array($context['source_set'] ?? null)
            || count($context['source_set']) < 1 || count($context['source_set']) > 2
            || ! array_is_list($context['source_set'])
            || count($context['source_set']) !== count(array_unique($context['source_set']))
            || ! (is_string($context['entity_key'] ?? null) || ($context['entity_key'] ?? null) === null)
            || (is_string($context['entity_key']) && (count($context['source_set']) !== 1
                || preg_match('~^[a-z0-9][a-z0-9._:-]{0,79}$~', $context['entity_key']) !== 1))
            || ($context['entity_key'] === null && count($context['source_set']) !== 2)) {
            return false;
        }
        foreach ($context['source_set'] as $source) {
            if (! is_string($source) || preg_match('~^document:[1-9][0-9]*/sheet:[1-9][0-9]*$~', $source) !== 1) {
                return false;
            }
        }

        return true;
    }
}
