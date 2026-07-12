<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateDecisionContextData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\NormativeCandidateSetData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeCandidateSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeMatchingWorkflow;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\LLMNormativeCandidateReranker;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\Reranking\NormativeCandidateRerankerInterface;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use DateTimeImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;

final class NormativeLaravelContainerIntegrationTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_real_provider_bindings_record_one_usage_and_workflow_fails_unavailable_without_fallback(): void
    {
        config()->set('estimate-generation.normative_matching.reranker.models', 'openai/gpt-5-mini');
        $store = new class implements AiUsageStore
        {
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $wire = new class implements RerankWireClient
        {
            public bool $timeout = false;

            public int $calls = 0;

            public array $models = [];

            public function provider(): string
            {
                return 'fake';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->calls++;
                $this->models[] = $model;
                if ($this->timeout) {
                    throw new RerankWireException('connection_failed');
                }

                return ['content' => json_encode(['selected_candidate_id' => '1', 'ordering' => ['1'], 'explanation_codes' => ['unit_match'], 'evidence_refs' => ['norm:1'], 'confidence' => 0.8, 'schema_version' => 'normative-rerank-v1'], JSON_THROW_ON_ERROR), 'model' => $model, 'usage_available' => true, 'input_tokens' => 10, 'output_tokens' => 5];
            }
        };
        $this->app->instance(RerankWireClient::class, $wire);
        $this->app->instance(AiUsageStore::class, $store);
        $this->app->instance(LLMProviderInterface::class, new class implements LLMProviderInterface
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
        foreach ([NormativeRerankerModelSet::class, AttemptAwareNormativeLlmClient::class, LLMNormativeCandidateReranker::class, NormativeCandidateRerankerInterface::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $reranker = $this->app->make(NormativeCandidateRerankerInterface::class);
        $result = $reranker->rerank($this->intent(), $this->context(), $this->set());
        self::assertSame('1', $result->selectedCandidateId);
        self::assertSame(['openai/gpt-5-mini'], $wire->models);
        self::assertCount(1, $store->rows);
        self::assertSame($this->app->make(NormativeRerankerModelSet::class)->version(), $this->context()->modelVersion);

        $wire->timeout = true;
        $this->app->instance(NormativeCandidateSource::class, new class($this->candidate()) implements NormativeCandidateSource
        {
            public function __construct(private NormativeCandidateData $candidate) {}

            public function find(int $organizationId, int $projectId, string $datasetVersion, string $query, int $limit, ?string $semanticIndexVersion): array
            {
                return [$this->candidate];
            }
        });
        foreach ([NormativeMatchingWorkflow::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }
        $workflow = $this->app->make(NormativeMatchingWorkflow::class);
        $failed = $workflow->match($this->intent(), $this->context(), true);
        self::assertSame('unavailable', $failed->status);
        self::assertSame(2, $wire->calls);
        self::assertCount(2, $store->rows);
        self::assertNull($failed->selectedCandidateId());
    }

    private function intent(): WorkIntentData
    {
        return new WorkIntentData(1, 2, 3, 'w', 'кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', 'v1', 'parsed', null, new DateTimeImmutable('2026-01-01'), ['norm:1']);
    }

    private function context(): NormativeCandidateDecisionContextData
    {
        $models = $this->app->make(NormativeRerankerModelSet::class);

        return new NormativeCandidateDecisionContextData(1, 2, 3, 'w', '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c', 'sha256:abc', 1, 'p1', 'normative-rerank-v1', $models->version(), ['norm:1']);
    }

    private function candidate(): NormativeCandidateData
    {
        return new NormativeCandidateData('1', 1, 1, 'v1', 'parsed', '08', 'Кладка', 'м2', 'area', 'кирпич', 'кладка', 'стена', '08', 'жилой', null, new DateTimeImmutable('2025-01-01'), null, 0.8, null, 'lex-v1', null, ['norm:1']);
    }

    private function set(): NormativeCandidateSetData
    {
        return new NormativeCandidateSetData(1, 2, 3, 'w', 'v1', 'lex-v1', null, [$this->candidate()]);
    }
}
