<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quality\Arbiter;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiAttemptAuthorizer;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPriceSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\ArbiterOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\Arbiter\AttemptAwareCompletenessArbiter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AttemptAwareCompletenessArbiterTest extends TestCase
{
    #[Test]
    public function it_stops_before_the_wire_when_the_conservative_input_token_limit_is_exceeded(): void
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

                return [];
            }
        };
        $authorizer = new class implements AiAttemptAuthorizer
        {
            public int $calls = 0;

            public function authorize(AiOperationContext $context, string $provider, string $model, int $maxInputTokens, int $maxOutputTokens, int $imageCount = 0, int $pageCount = 0): AiPriceSnapshot
            {
                $this->calls++;

                return AiPriceSnapshot::fromArray([]);
            }

            public function claimWire(string $attemptId): bool
            {
                return true;
            }

            public function releaseBeforeWire(string $attemptId): void {}
        };
        $arbiter = new AttemptAwareCompletenessArbiter(
            $wire,
            new class implements AiUsageStore
            {
                public function record(AiUsageData $data): void {}
            },
            $authorizer,
            'openai/gpt-5-mini',
            'completeness-arbiter:v1',
            'completeness-arbiter:v1',
            500,
            70,
            15,
        );

        try {
            $arbiter->review([
                'input_hash' => 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                'source_text' => str_repeat('x', 1_500),
                'operation' => $this->operation(),
            ]);
            self::fail('Expected the conservative input limit to prevent the wire call.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, $authorizer->calls);
        self::assertSame(0, $wire->calls);
    }

    #[Test]
    public function it_does_not_return_a_verdict_when_the_required_usage_audit_cannot_be_recorded(): void
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

                return [
                    'content' => json_encode(['outcome' => 'passed', 'findings' => []], JSON_THROW_ON_ERROR),
                    'model' => $model,
                    'input_tokens' => 123,
                    'output_tokens' => 45,
                    'usage_available' => true,
                ];
            }
        };
        $arbiter = new AttemptAwareCompletenessArbiter(
            $wire,
            new class implements AiUsageStore
            {
                public function record(AiUsageData $data): void
                {
                    throw new \RuntimeException('store unavailable');
                }
            },
            $this->authorizer(),
            'openai/gpt-5-mini',
            'completeness-arbiter:v1',
            'completeness-arbiter:v1',
            1_000,
            70,
            15,
        );

        try {
            $arbiter->review([
                'input_hash' => 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
                'operation' => $this->operation(),
            ]);
            self::fail('Expected a missing usage audit to reject the verdict.');
        } catch (\RuntimeException $exception) {
            self::assertSame('usage_recording_failed', $exception->getMessage());
        }

        self::assertSame(1, $wire->calls);
    }

    #[Test]
    public function it_uses_its_own_model_and_token_limits_and_records_the_attempt(): void
    {
        $wire = new class implements RerankWireClient
        {
            /** @var array<string, mixed> */
            public array $options = [];

            public function provider(): string
            {
                return 'timeweb';
            }

            public function call(string $model, array $messages, array $options): array
            {
                $this->options = $options;

                return [
                    'content' => json_encode(['outcome' => 'passed', 'findings' => []], JSON_THROW_ON_ERROR),
                    'model' => $model,
                    'input_tokens' => 123,
                    'output_tokens' => 45,
                    'usage_available' => true,
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
        $authorizer = $this->authorizer();
        $arbiter = new AttemptAwareCompletenessArbiter(
            $wire,
            $store,
            $authorizer,
            'openai/gpt-5-mini',
            'completeness-arbiter:v1',
            'completeness-arbiter:v1',
            500,
            70,
            15,
        );

        $response = $arbiter->review([
            'input_hash' => 'sha256:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
            'schema_version' => 'completeness-arbiter:v1',
            'scope_keys' => ['heating'],
            'operation' => $this->operation(),
        ]);

        self::assertSame('passed', $response['outcome']);
        self::assertSame(70, $wire->options['max_tokens']);
        self::assertSame(500, $authorizer->maxInputTokens);
        self::assertSame(70, $authorizer->maxOutputTokens);
        self::assertCount(1, $store->rows);
        self::assertSame('validate_draft', $store->rows[0]->context->stage);
        self::assertSame('completeness_review', $store->rows[0]->context->operation);
    }

    private function operation(): ArbiterOperationContext
    {
        return new ArbiterOperationContext(
            10,
            20,
            30,
            '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            'input-v1',
            1,
        );
    }

    private function authorizer(): AiAttemptAuthorizer
    {
        return new class implements AiAttemptAuthorizer
        {
            public int $maxInputTokens = 0;

            public int $maxOutputTokens = 0;

            public function authorize(AiOperationContext $context, string $provider, string $model, int $maxInputTokens, int $maxOutputTokens, int $imageCount = 0, int $pageCount = 0): AiPriceSnapshot
            {
                $this->maxInputTokens = $maxInputTokens;
                $this->maxOutputTokens = $maxOutputTokens;

                return AiPriceSnapshot::fromArray([]);
            }

            public function claimWire(string $attemptId): bool
            {
                return true;
            }

            public function releaseBeforeWire(string $attemptId): void {}
        };
    }
}
