<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class AttemptAwareWorkCompositionLlmClient implements WorkCompositionLlmClient
{
    public function __construct(
        private LLMProviderInterface $provider,
        private AttemptAwareNormativeLlmClient $client,
    ) {}

    public function isAvailable(): bool
    {
        return $this->provider->isAvailable();
    }

    public function chat(array $messages, PipelineContext $context, string $candidateSetHash): array
    {
        try {
            return $this->client->chat($messages, [
                'profile' => 'json',
                'temperature' => 0,
                'max_tokens' => 900,
                'timeout' => 60,
            ], [
                'organization_id' => $context->organizationId,
                'project_id' => $context->projectId,
                'session_id' => $context->sessionId,
                'work_item_key' => 'residential-work-composition',
                'checkpoint_claim_token' => (string) $context->claimToken,
                'input_version' => $context->inputVersion,
                'logical_attempt' => (int) $context->stageAttempt,
                'candidate_set_hash' => $candidateSetHash,
                'prompt_version' => 'residential-work-composition:v2',
                'schema_version' => AiResidentialWorkCompositionAdvisor::SCHEMA_VERSION,
                'model_version' => 'estimate-generation-effective-settings',
                'dataset_versions' => [ResidentialWorkCompositionCatalog::VERSION],
                'model_strategy' => AttemptAwareNormativeLlmClient::MODEL_STRATEGY_CONFIGURED_FALLBACKS,
            ]);
        } catch (Throwable $exception) {
            try {
                Log::warning('[EstimateGeneration] Work composition AI call failed', array_filter([
                    'organization_id' => $context->organizationId,
                    'project_id' => $context->projectId,
                    'session_id' => $context->sessionId,
                    'stage_attempt' => (int) $context->stageAttempt,
                    'exception_class' => $exception::class,
                    'attempt_status' => $exception instanceof RerankWireException
                        ? $exception->attemptStatus
                        : null,
                ], static fn (mixed $value): bool => $value !== null));
            } catch (Throwable) {
            }

            throw $exception;
        }
    }
}
