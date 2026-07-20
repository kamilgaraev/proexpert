<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\PipelineContext;
use App\BusinessModules\Addons\EstimateGeneration\Planning\AttemptAwareWorkCompositionLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsPair;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use App\BusinessModules\Features\AIAssistant\Services\LLM\LLMProviderInterface;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        self::assertSame(4096, $wire->options['max_tokens']);
        self::assertSame(0, $wire->options['temperature']);
        self::assertSame('json', $wire->options['profile']);
        self::assertSame(60, $wire->options['timeout']);
    }

    #[Test]
    public function client_uses_configured_model_fallbacks_instead_of_effective_model_retries(): void
    {
        $wire = new class implements RerankWireClient
        {
            /** @var list<string> */
            public array $models = [];

            public function provider(): string
            {
                return 'test';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->models[] = $model;
                if ($model === 'provider/fallback-a') {
                    throw new RuntimeException('first fallback failed');
                }

                return [
                    'content' => '{"schema_version":"residential-work-composition-advice:v2"}',
                    'model' => $model,
                    'usage_available' => false,
                ];
            }
        };
        $usage = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void {}
        };
        $client = new AttemptAwareWorkCompositionLlmClient(
            $this->provider(),
            new AttemptAwareNormativeLlmClient(
                $wire,
                $usage,
                modelSet: new NormativeRerankerModelSet(['provider/fallback-a', 'provider/fallback-b']),
                settingsResolver: $this->settingsResolver(),
            ),
        );

        $response = $client->chat([], $this->context(), 'sha256:candidates');

        self::assertSame(['provider/fallback-a', 'provider/fallback-b'], $wire->models);
        self::assertInstanceOf(EffectiveEstimateGenerationSettings::class, $response['effective_settings']);
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

    private function provider(): LLMProviderInterface
    {
        return new class implements LLMProviderInterface
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
                return 'provider/effective';
            }
        };
    }

    private function settingsResolver(): EffectiveSettingsResolver
    {
        $snapshot = [
            'schema_version' => 2,
            'models' => ['vision' => 'provider/vision', 'classification' => 'provider/classification', 'normative_matching' => 'provider/effective'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 120, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 10, 'classification' => 30, 'normative_matching' => 600],
            'retries' => ['vision' => 2, 'classification' => 1, 'normative_matching' => 3],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7800', 'normative_matching' => '0.8200'],
            'enabled_formats' => ['pdf'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ];
        $global = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 40, 'scope' => 'global', 'organization_id' => null, 'version' => 1,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
        ], 75);
        $effective = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 41, 'scope' => 'organization', 'organization_id' => 75, 'version' => 1,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
        ], 75);
        $store = new class($global, $effective) implements EffectiveSettingsOperationStore
        {
            public function __construct(
                private readonly EffectiveEstimateGenerationSettings $global,
                private readonly EffectiveEstimateGenerationSettings $effective,
            ) {}

            public function pin(string $correlationId, int $organizationId, int $sessionId): EffectiveSettingsPair
            {
                return new EffectiveSettingsPair($this->global, $this->effective);
            }
        };

        return new EffectiveSettingsResolver($store);
    }
}
