<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

final readonly class AttemptAwareNormativeLlmClient
{
    public function __construct(
        private RerankWireClient $wire,
        private AiUsageStore $usageStore,
        private ?array $configuredModels = null,
        private ?array $configuredPrices = null,
    ) {}

    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @param  array<string, mixed>  $domainContext
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $options, array $domainContext): array
    {
        $operation = RerankOperationContext::fromArray($domainContext);
        $organizationId = $operation->organizationId;
        $projectId = $operation->projectId;
        $sessionId = $operation->sessionId;
        $seed = json_encode(get_object_vars($operation), JSON_THROW_ON_ERROR);
        $correlationId = AiOperationContext::deterministicId('rerank|'.$seed);
        $models = $this->models();
        $last = null;

        foreach ($models as $index => $model) {
            $started = hrtime(true);
            $status = 'connection_failed';
            $httpCode = null;
            $response = [];
            try {
                $response = $this->wire->call($model, $messages, $options);
                $reportedModel = $response['model'] ?? null;
                $content = trim((string) ($response['content'] ?? ''));
                $decoded = json_decode($content, true);
                $status = $content === '' || ! is_array($decoded)
                    || (is_string($reportedModel) && $reportedModel !== $model)
                    ? 'malformed_response' : 'succeeded';
                if ($status === 'succeeded') {
                    return $response;
                }
                $last = new RerankWireException('malformed_response');
            } catch (RerankWireException $exception) {
                $status = $exception->attemptStatus;
                $httpCode = $exception->httpCode;
                $last = $exception;
            } catch (Throwable $exception) {
                $last = $exception;
            } finally {
                $this->record($correlationId, $organizationId, $projectId, $sessionId, $model, $index + 1, $status, $httpCode, $response, $started);
            }
        }

        throw $last ?? new InvalidArgumentException('No reranker models configured.');
    }

    /** @param array<string, mixed> $response */
    private function record(string $correlationId, int $organizationId, int $projectId, int $sessionId, string $model, int $ordinal, string $status, ?int $httpCode, array $response, int $started): void
    {
        try {
            $context = new AiOperationContext($correlationId, AiOperationContext::deterministicId($correlationId.'|'.$model.'|'.$ordinal), $organizationId, $projectId, $sessionId, 'match_normatives', 'rerank', $ordinal);
            $usageAvailable = ($response['usage_available'] ?? false) === true;
            $input = $usageAvailable ? max(0, (int) ($response['input_tokens'] ?? 0)) : 0;
            $output = $usageAvailable ? max(0, (int) ($response['output_tokens'] ?? 0)) : 0;
            $snapshot = AiPriceSnapshot::fromArray($this->price($model));
            $this->usageStore->record(new AiUsageData(
                context: $context,
                provider: $this->wire->provider(),
                requestedModel: $model,
                reportedModel: is_string($response['model'] ?? null) ? $response['model'] : null,
                status: $status,
                durationMs: (int) max(0, round((hrtime(true) - $started) / 1_000_000)),
                usageStatus: $usageAvailable ? 'measured' : 'unavailable',
                inputTokens: $input,
                outputTokens: $output,
                httpCode: $httpCode,
                priceSnapshot: $snapshot,
            ));
        } catch (Throwable $exception) {
            try {
                Log::error('[EstimateGeneration] Reranker usage recording failed', ['exception_class' => $exception::class]);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<int, string> */
    private function models(): array
    {
        $models = $this->configuredModels ?? config('estimate-generation.normative_matching.reranker.models', []);
        $models = is_string($models) ? explode(',', $models) : $models;

        return array_values(array_filter(array_map(static fn (mixed $model): string => trim((string) $model), is_array($models) ? $models : [])));
    }

    /** @return array<string, mixed> */
    private function price(string $model): array
    {
        $price = $this->configuredPrices !== null
            ? ($this->configuredPrices[$model] ?? [])
            : config("estimate-generation.ai_pricing.timeweb.models.{$model}", []);

        return is_array($price) ? $price : [];
    }
}
