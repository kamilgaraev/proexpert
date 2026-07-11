<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AttemptAwareNormativeLlmClient;
use App\BusinessModules\Addons\EstimateGeneration\Observability\RerankWireClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AttemptAwareNormativeLlmClientTest extends TestCase
{
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
        $client->chat([], [], $this->context('018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d'));
        self::assertNotSame($firstClaimId, $store->rows[2]->context->attemptId);
    }

    /** @return array<string, mixed> */
    private function context(string $claim): array
    {
        return ['organization_id' => 1, 'project_id' => 2, 'session_id' => 3, 'checkpoint_claim_token' => $claim,
            'input_version' => 'sha256:abc', 'work_item_key' => 'work-1', 'logical_attempt' => 1];
    }
}
