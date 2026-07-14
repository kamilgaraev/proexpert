<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiAttemptAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireException;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttemptAwareNormativeLlmClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = new Container;
        Container::setInstance($app);
        $app->instance('config', new Repository);
        $app->instance('log', new class
        {
            public function error(string $message, array $context = []): void {}
        });
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
            public function record(AiUsageData $data): void {}
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
        }
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

    /** @return array<string, mixed> */
    private function context(string $claim): array
    {
        return ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'checkpoint_claim_token' => $claim,
            'input_version' => 'sha256:abc', 'work_item_key' => 'work-1', 'logical_attempt' => 1];
    }
}

final class RejectingWireClaimAuthorizer implements AiAttemptAuthorizer
{
    public int $claims = 0;

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

        return false;
    }

    public function releaseBeforeWire(string $attemptId): void {}
}
