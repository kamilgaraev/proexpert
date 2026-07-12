<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\LLMNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

final class NormativeRerankerContainerTest extends TestCase
{
    public function test_container_uses_one_resolved_model_set_for_wire_and_usage(): void
    {
        $wire = new class implements RerankWireClient
        {
            public array $models = [];

            public function provider(): string
            {
                return 'fake';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->models[] = $model;

                return ['content' => '{}', 'model' => $model, 'usage_available' => true];
            }
        };
        $store = new class implements AiUsageStore
        {
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $container = new Container;
        $container->instance(RerankWireClient::class, $wire);
        $container->instance(AiUsageStore::class, $store);
        $container->instance(NormativeRerankerModelSet::class, new NormativeRerankerModelSet(['openai/gpt-5-mini']));
        $container->instance(LLMProviderInterface::class, new class implements LLMProviderInterface
        {
            public function chat(array $messages, array $options = []): array
            {
                return [];
            }

            public function countTokens(string $text): int
            {
                return strlen($text);
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'openai/gpt-5-mini';
            }
        });
        $container->singleton(AttemptAwareNormativeLlmClient::class, fn (Container $app) => new AttemptAwareNormativeLlmClient($app->make(RerankWireClient::class), $app->make(AiUsageStore::class), null, [], $app->make(NormativeRerankerModelSet::class)));
        $container->singleton(NormativeCandidateRerankerInterface::class, fn (Container $app) => new LLMNormativeCandidateReranker($app->make(LLMProviderInterface::class), $app->make(AttemptAwareNormativeLlmClient::class)));

        $client = $container->make(AttemptAwareNormativeLlmClient::class);
        $response = $client->chat([], [], ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'checkpoint_claim_token' => '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'input_version' => 'sha256:abc', 'work_item_key' => 'w', 'logical_attempt' => 1]);

        self::assertSame('{}', $response['content']);
        self::assertSame(['openai/gpt-5-mini'], $wire->models);
        self::assertCount(1, $store->rows);
        self::assertInstanceOf(LLMNormativeCandidateReranker::class, $container->make(NormativeCandidateRerankerInterface::class));
    }
}
