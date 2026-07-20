<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\AttemptAwareWorkCompositionLlmClient;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttemptAwareWorkCompositionLlmClientTest extends TestCase
{
    #[Test]
    public function client_limits_model_output_for_compact_response(): void
    {
        $wire = new class implements RerankWireClient
        {
            public array $options = [];

            public function provider(): string
            {
                return 'test';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->options = $options;

                return [
                    'content' => '{"schema_version":"residential-work-composition-advice:v2"}',
                    'model' => $model,
                    'usage_available' => true,
                    'input_tokens' => 10,
                    'output_tokens' => 10,
                ];
            }
        };
        $provider = new class implements LLMProviderInterface
        {
            public function chat(array $messages, array $options = []): array
            {
                return [];
            }

            public function countTokens(string $text): int
            {
                return 0;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getModel(): string
            {
                return 'model-a';
            }
        };
        $usage = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void {}
        };
        $client = new AttemptAwareWorkCompositionLlmClient(
            $provider,
            new AttemptAwareNormativeLlmClient($wire, $usage, ['model-a'], []),
        );

        $client->chat([], $this->context(), 'sha256:candidates');

        self::assertSame(900, $wire->options['max_tokens']);
        self::assertSame(0, $wire->options['temperature']);
        self::assertSame('json', $wire->options['profile']);
    }

    private function context(): PipelineContext
    {
        return new PipelineContext(
            sessionId: 58,
            organizationId: 75,
            projectId: 89,
            stateVersion: 1,
            inputVersion: 'input:v1',
            sessionStatus: 'processing',
            claimToken: '00000000-0000-4000-8000-000000000001',
            stageAttempt: 1,
            leaseExpiresAt: new DateTimeImmutable('+5 minutes'),
        );
    }
}
