<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use Closure;
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
        private ?NormativeRerankerModelSet $modelSet = null,
        private ?EffectiveSettingsResolver $settingsResolver = null,
        private ?AiAttemptAuthorizer $budgetAuthorizer = null,
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
        $heartbeat = is_callable($domainContext['heartbeat_callback'] ?? null)
            ? Closure::fromCallable($domainContext['heartbeat_callback'])
            : null;
        $organizationId = $operation->organizationId;
        $projectId = $operation->projectId;
        $sessionId = $operation->sessionId;
        $seed = json_encode([
            ...get_object_vars($operation),
            'candidate_set_hash' => (string) ($domainContext['candidate_set_hash'] ?? ''),
            'prompt_version' => (string) ($domainContext['prompt_version'] ?? ''),
            'schema_version' => (string) ($domainContext['schema_version'] ?? ''),
            'model_version' => (string) ($domainContext['model_version'] ?? ''),
            'dataset_versions' => is_array($domainContext['dataset_versions'] ?? null)
                ? array_values(array_map('strval', $domainContext['dataset_versions'])) : [],
        ], JSON_THROW_ON_ERROR);
        $correlationId = AiOperationContext::deterministicId('rerank|'.$seed);
        $effective = $this->settingsResolver?->forOperation($correlationId, $organizationId, $sessionId);
        $models = $this->models($effective);
        $options['timeout'] = $effective?->timeoutSeconds('normative_matching') ?? ($options['timeout'] ?? null);
        $last = null;

        foreach ($models as $index => $model) {
            $heartbeat?->__invoke();
            $attemptContext = new AiOperationContext(
                $correlationId,
                AiPhysicalAttemptIdentity::fromParts($operation->checkpointClaimToken, $model, $index + 1, $operation->inputVersion.'|'.($domainContext['prompt_version'] ?? 'rerank:v1')),
                $organizationId,
                $projectId,
                $sessionId,
                'match_normatives',
                'rerank',
                $index + 1,
            );
            $priceSnapshot = $this->budgetAuthorizer?->authorize(
                $attemptContext,
                $this->wire->provider(),
                $model,
                max(1, (int) config('estimate-generation.normative_matching.reranker.max_input_tokens', 64_000)),
                max(1, (int) ($options['max_tokens'] ?? 800)),
            ) ?? AiPriceSnapshot::fromArray($this->price($model));
            $started = hrtime(true);
            $status = 'connection_failed';
            $httpCode = null;
            $response = [];
            $wireClaimed = false;
            try {
                $this->claimWireOrFail($attemptContext->attemptId);
                $wireClaimed = true;
                $response = $this->wire->call($model, $messages, $options);
                $reportedModel = $response['model'] ?? null;
                $content = trim((string) ($response['content'] ?? ''));
                $decoded = json_decode($content, true);
                $status = $content === '' || ! is_array($decoded)
                    || (is_string($reportedModel) && $reportedModel !== $model)
                    ? 'malformed_response' : 'succeeded';
                if ($status === 'succeeded') {
                    if ($effective !== null) {
                        $response['effective_settings'] = $effective;
                    }

                    return $response;
                }
                $last = new RerankWireException('malformed_response');
            } catch (RerankWireException $exception) {
                $status = $exception->attemptStatus;
                $httpCode = $exception->httpCode;
                $last = $exception;
                if ($exception->attemptStatus === 'wire_replay_forbidden') {
                    throw $exception;
                }
            } catch (Throwable $exception) {
                $last = $exception;
            } finally {
                if ($wireClaimed) {
                    $this->record($attemptContext, $model, $status, $httpCode, $response, $started, $priceSnapshot);
                }
                $heartbeat?->__invoke();
            }
        }

        throw $last ?? new InvalidArgumentException('No reranker models configured.');
    }

    private function claimWireOrFail(string $attemptId): void
    {
        if ($this->budgetAuthorizer === null) {
            return;
        }
        try {
            $claimed = $this->budgetAuthorizer->claimWire($attemptId);
        } catch (Throwable $exception) {
            try {
                $this->budgetAuthorizer->releaseBeforeWire($attemptId);
            } catch (Throwable) {
            }
            throw $exception;
        }
        if (! $claimed) {
            throw new RerankWireException('wire_replay_forbidden');
        }
    }

    /** @param array<string, mixed> $response */
    private function record(AiOperationContext $context, string $model, string $status, ?int $httpCode, array $response, int $started, AiPriceSnapshot $snapshot): void
    {
        try {
            $usageAvailable = ($response['usage_available'] ?? false) === true;
            $input = $usageAvailable ? max(0, (int) ($response['input_tokens'] ?? 0)) : 0;
            $output = $usageAvailable ? max(0, (int) ($response['output_tokens'] ?? 0)) : 0;
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
    private function models(?EffectiveEstimateGenerationSettings $effective = null): array
    {
        if ($effective !== null) {
            return array_fill(
                0,
                $effective->retryAttempts('normative_matching') + 1,
                $effective->model('normative_matching'),
            );
        }
        if ($this->configuredModels === null) {
            return ($this->modelSet ?? new NormativeRerankerModelSet)->models;
        }
        $models = $this->configuredModels;
        $models = is_string($models) ? explode(',', $models) : $models;

        return array_values(array_filter(array_map(static fn (mixed $model): string => trim((string) $model), is_array($models) ? $models : [])));
    }

    /** @return array<string, mixed> */
    private function price(string $model): array
    {
        $price = $this->configuredPrices[$model] ?? [];

        return is_array($price) ? $price : [];
    }
}
