<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeRerankerModelSet;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiAttemptAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveEstimateGenerationSettings;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsOperationStore;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsPair;
use App\BusinessModules\Addons\EstimateGeneration\Settings\EffectiveSettingsResolver;
use App\BusinessModules\Addons\EstimateGeneration\Settings\SettingsSnapshotHash;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttemptAwareNormativeLlmClientTest extends TestCase
{
    private object $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);
        $this->logger = new class
        {
            /** @var list<string> */
            public array $errors = [];

            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $warnings = [];

            public function error(string $message, array $context = []): void
            {
                $this->errors[] = $message;
            }

            public function warning(string $message, array $context = []): void
            {
                $this->warnings[] = ['message' => $message, 'context' => $context];
            }
        };
        $app->instance('log', $this->logger);
        Facade::setFacadeApplication($app);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    #[Test]
    public function replay_without_claim_never_calls_reranker_wire(): void
    {
        $wire = new class implements RerankWireClient
        {
            public int $calls = 0;

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->calls++;

                return ['content' => '{}', 'model' => $model, 'usage_available' => false];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var list<AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $authorizer = new RejectingWireClaimAuthorizer;
        $client = new AttemptAwareNormativeLlmClient(
            $wire,
            $store,
            ['model-a', 'model-b'],
            [],
            null,
            null,
            $authorizer,
        );

        try {
            $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
            self::fail('Replay without claim reached reranker wire.');
        } catch (RerankWireException $exception) {
            self::assertSame('wire_replay_forbidden', $exception->attemptStatus);
        }
        self::assertSame(0, $wire->calls);
        self::assertSame(1, $authorizer->claims);
        self::assertSame(0, $authorizer->releases);
        self::assertSame([], $store->rows);
        self::assertSame([], $this->logger->errors);

        $authorizer->claimGranted = true;
        $result = $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));

        self::assertSame('{}', $result['content']);
        self::assertSame(1, $wire->calls);
        self::assertCount(1, $store->rows);
        self::assertSame('succeeded', $store->rows[0]->status);
        self::assertSame($authorizer->attemptIds[0], $authorizer->attemptIds[1]);
        self::assertSame($authorizer->attemptIds[0], $store->rows[0]->context->attemptId);
        self::assertSame(0, $authorizer->releases);
    }

    #[Test]
    public function each_model_wire_attempt_gets_one_row_and_new_claim_gets_new_ids(): void
    {
        $wire = new class implements RerankWireClient
        {
            public int $calls = 0;

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->calls++;
                if ($model === 'model-a') {
                    throw new RuntimeException('wire failed');
                }

                return ['content' => '{}', 'provider' => 'timeweb', 'model' => $model, 'input_tokens' => 10, 'output_tokens' => 2, 'usage_available' => true];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var array<int, AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $client = new AttemptAwareNormativeLlmClient($wire, $store, ['model-a', 'model-b'], []);

        $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
        self::assertCount(2, $store->rows);
        self::assertSame(['connection_failed', 'succeeded'], array_map(fn (AiUsageData $row): string => $row->status, $store->rows));
        self::assertNotSame($store->rows[0]->context->attemptId, $store->rows[1]->context->attemptId);

        $firstClaimId = $store->rows[0]->context->attemptId;
        $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
        self::assertSame($firstClaimId, $store->rows[2]->context->attemptId);
        self::assertSame($store->rows[0]->context->correlationId, $store->rows[2]->context->correlationId);

        $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d'));
        self::assertNotSame($firstClaimId, $store->rows[4]->context->attemptId);
    }

    #[Test]
    public function malformed_wire_exception_records_exactly_one_malformed_attempt(): void
    {
        $wire = new class implements RerankWireClient
        {
            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                throw new RerankWireException('malformed_response');
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var array<int, AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $client = new AttemptAwareNormativeLlmClient($wire, $store, ['model-a'], []);

        try {
            $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
            self::fail('Malformed response must fail the routed call.');
        } catch (RerankWireException) {
        }

        self::assertCount(1, $store->rows);
        self::assertSame('malformed_response', $store->rows[0]->status);
        self::assertNull($store->rows[0]->httpCode);
    }

    #[Test]
    public function missing_usage_is_unavailable_and_measurement_construction_failure_never_masks_success(): void
    {
        $wire = new class implements RerankWireClient
        {
            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                return ['content' => '{}', 'model' => $model, 'usage_available' => false];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var array<int, AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };

        $normal = new AttemptAwareNormativeLlmClient($wire, $store, ['model-a'], []);
        self::assertSame('{}', $normal->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'))['content']);
        self::assertSame('unavailable', $store->rows[0]->usageStatus);

        $invalidMeasurement = new AttemptAwareNormativeLlmClient($wire, $store, ['invalid model with spaces'], []);
        self::assertSame('{}', $invalidMeasurement->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d'))['content']);
    }

    #[Test]
    public function invalid_json_is_one_malformed_attempt_and_not_a_success(): void
    {
        $wire = new class implements RerankWireClient
        {
            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                return ['content' => 'plain text', 'model' => $model, 'usage_available' => false];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var array<int, AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };

        $this->expectException(RerankWireException::class);
        try {
            (new AttemptAwareNormativeLlmClient($wire, $store, ['model-a'], []))
                ->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
        } finally {
            self::assertCount(1, $store->rows);
            self::assertSame('malformed_response', $store->rows[0]->status);
            self::assertSame('invalid_json', $this->logger->warnings[0]['context']['reason']);
            self::assertArrayNotHasKey('content', $this->logger->warnings[0]['context']);
        }
    }

    #[Test]
    public function empty_response_stopped_by_length_reports_output_budget_exhaustion(): void
    {
        $wire = new class implements RerankWireClient
        {
            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                return [
                    'content' => '',
                    'model' => $model,
                    'usage_available' => true,
                    'input_tokens' => 100,
                    'output_tokens' => 4096,
                    'finish_reason' => 'length',
                ];
            }
        };
        $store = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void {}
        };

        $this->expectException(RerankWireException::class);
        try {
            (new AttemptAwareNormativeLlmClient($wire, $store, ['openai/gpt-5-mini'], []))
                ->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
        } finally {
            self::assertSame('output_budget_exhausted', $this->logger->warnings[0]['context']['reason']);
            self::assertSame('length', $this->logger->warnings[0]['context']['finish_reason']);
            self::assertArrayNotHasKey('content', $this->logger->warnings[0]['context']);
        }
    }

    #[Test]
    public function json_profile_accepts_one_fenced_json_object_and_returns_canonical_content(): void
    {
        $wire = new class implements RerankWireClient
        {
            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                return [
                    'content' => "```json\n{\"schema_version\":\"residential-work-composition-advice:v2\"}\n```",
                    'model' => $model,
                    'usage_available' => true,
                    'input_tokens' => 20,
                    'output_tokens' => 30,
                ];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var list<AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };

        $response = (new AttemptAwareNormativeLlmClient($wire, $store, ['provider/model'], []))
            ->chat([], ['profile' => 'json'], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));

        self::assertSame('{"schema_version":"residential-work-composition-advice:v2"}', $response['content']);
        self::assertSame('succeeded', $store->rows[0]->status);
    }

    #[Test]
    public function recorder_failure_never_masks_typed_provider_error(): void
    {
        $wire = new class implements RerankWireClient
        {
            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                throw new RerankWireException('http_failed', 503);
            }
        };
        $store = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void
            {
                throw new RuntimeException('recorder failed');
            }
        };

        $this->expectException(RerankWireException::class);
        $this->expectExceptionMessage('reranker_wire_failed');
        (new AttemptAwareNormativeLlmClient($wire, $store, ['model-a'], []))
            ->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'));
    }

    #[Test]
    public function configured_fallback_strategy_uses_distinct_models_with_effective_settings(): void
    {
        $wire = new class implements RerankWireClient
        {
            /** @var list<string> */
            public array $models = [];

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->models[] = $model;
                if ($model === 'provider/fallback-a') {
                    throw new RuntimeException('first fallback failed');
                }

                return ['content' => '{}', 'model' => $model, 'usage_available' => false];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var list<AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $client = new AttemptAwareNormativeLlmClient(
            $wire,
            $store,
            modelSet: new NormativeRerankerModelSet(['provider/fallback-a', 'provider/fallback-b']),
            settingsResolver: $this->settingsResolver(timeout: 600, retries: 3),
        );

        $response = $client->chat([], [], [
            ...$this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'),
            'model_strategy' => AttemptAwareNormativeLlmClient::MODEL_STRATEGY_CONFIGURED_FALLBACKS,
        ]);

        self::assertSame(['provider/fallback-a', 'provider/fallback-b'], $wire->models);
        self::assertInstanceOf(EffectiveEstimateGenerationSettings::class, $response['effective_settings']);
        self::assertCount(2, $store->rows);
    }

    #[Test]
    public function normalized_caller_timeout_caps_effective_timeout_and_changes_correlation_identity(): void
    {
        $wire = new class implements RerankWireClient
        {
            /** @var list<array<string, mixed>> */
            public array $options = [];

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->options[] = $options;

                return ['content' => '{}', 'model' => $model, 'usage_available' => false];
            }
        };
        $store = new class implements AiUsageStore
        {
            /** @var list<AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $this->rows[] = $data;
            }
        };
        $client = new AttemptAwareNormativeLlmClient(
            $wire,
            $store,
            modelSet: new NormativeRerankerModelSet(['provider/fallback-a']),
            settingsResolver: $this->settingsResolver(timeout: 600, retries: 0),
        );
        $context = $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c');

        $client->chat([], ['timeout' => 60], [
            ...$context,
            'model_strategy' => AttemptAwareNormativeLlmClient::MODEL_STRATEGY_CONFIGURED_FALLBACKS,
        ]);
        $client->chat([], ['timeout' => 61], [
            ...$context,
            'model_strategy' => AttemptAwareNormativeLlmClient::MODEL_STRATEGY_CONFIGURED_FALLBACKS,
        ]);

        self::assertSame(60, $wire->options[0]['timeout']);
        self::assertSame(61, $wire->options[1]['timeout']);
        self::assertNotSame($store->rows[0]->context->correlationId, $store->rows[1]->context->correlationId);
    }

    #[Test]
    public function unknown_model_strategy_is_rejected_before_wire(): void
    {
        $wire = new class implements RerankWireClient
        {
            public int $calls = 0;

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->calls++;

                return ['content' => '{}', 'model' => $model, 'usage_available' => false];
            }
        };
        $store = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void {}
        };
        $client = new AttemptAwareNormativeLlmClient($wire, $store, ['provider/model'], []);

        $this->expectException(InvalidArgumentException::class);
        try {
            $client->chat([], [], [
                ...$this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c'),
                'model_strategy' => 'caller-controlled-models',
            ]);
        } finally {
            self::assertSame(0, $wire->calls);
        }
    }

    /** @return array<string, mixed> */
    private function context(string $claim): array
    {
        return ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'checkpoint_claim_token' => $claim,
            'input_version' => 'sha256:abc', 'work_item_key' => 'work-1', 'logical_attempt' => 1];
    }

    private function settingsResolver(int $timeout, int $retries): EffectiveSettingsResolver
    {
        $snapshot = [
            'schema_version' => 2,
            'models' => ['vision' => 'provider/vision', 'classification' => 'provider/classification', 'normative_matching' => 'provider/effective'],
            'limits' => ['max_files' => 8, 'max_pages_per_file' => 120, 'max_total_pages' => 500],
            'timeouts' => ['vision' => 10, 'classification' => 30, 'normative_matching' => $timeout],
            'retries' => ['vision' => 2, 'classification' => 1, 'normative_matching' => $retries],
            'confidence' => ['classification' => '0.7000', 'geometry' => '0.7800', 'normative_matching' => '0.8200'],
            'enabled_formats' => ['pdf'],
            'manual_review' => ['low_confidence' => true],
            'budgets' => ['daily' => '250.00', 'monthly' => '4000.00', 'currency' => 'RUB'],
        ];
        $global = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 40, 'scope' => 'global', 'organization_id' => null, 'version' => 1,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
        ], 1);
        $effective = EffectiveEstimateGenerationSettings::fromRecord([
            'snapshot_id' => 41, 'scope' => 'organization', 'organization_id' => 1, 'version' => 1,
            'snapshot_hash' => SettingsSnapshotHash::calculate($snapshot), 'snapshot' => $snapshot,
        ], 1);
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

final class RejectingWireClaimAuthorizer implements AiAttemptAuthorizer
{
    public int $claims = 0;

    public int $releases = 0;

    public bool $claimGranted = false;

    /** @var list<string> */
    public array $attemptIds = [];

    public function authorize(
        AiOperationContext $context,
        string $provider,
        string $model,
        int $maxInputTokens,
        int $maxOutputTokens,
        int $imageCount = 0,
        int $pageCount = 0,
    ): AiPriceSnapshot {
        return AiPriceSnapshot::fromArray([]);
    }

    public function claimWire(string $attemptId): bool
    {
        $this->claims++;
        $this->attemptIds[] = $attemptId;

        return $this->claimGranted;
    }

    public function releaseBeforeWire(string $attemptId): void
    {
        $this->releases++;
    }
}
