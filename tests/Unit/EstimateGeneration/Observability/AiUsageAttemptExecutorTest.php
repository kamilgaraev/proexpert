<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageAttemptExecutor;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\UsageInvariantViolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AiUsageAttemptExecutorTest extends TestCase
{
    #[Test]
    public function same_attempt_and_fingerprint_is_idempotent_but_collision_is_rejected(): void
    {
        $store = new class implements AiUsageStore
        {
            /** @var array<string, AiUsageData> */
            public array $rows = [];

            public function record(AiUsageData $data): void
            {
                $existing = $this->rows[$data->context->attemptId] ?? null;
                if ($existing !== null && $existing->immutableFingerprint !== $data->immutableFingerprint) {
                    throw new UsageInvariantViolation;
                }
                $this->rows[$data->context->attemptId] = $existing ?? $data;
            }
        };
        $data = $this->data();

        $store->record($data);
        $store->record($data);
        self::assertCount(1, $store->rows);

        $this->expectException(UsageInvariantViolation::class);
        $store->record($this->data(durationMs: 2));
    }

    #[Test]
    public function recorder_failure_never_masks_provider_result_or_error(): void
    {
        $store = new class implements AiUsageStore
        {
            public function record(AiUsageData $data): void
            {
                throw new RuntimeException('recorder failed');
            }
        };
        $executor = new AiUsageAttemptExecutor($store);

        self::assertSame('ok', $executor->execute(fn (): string => 'ok', fn (): AiUsageData => $this->data()));

        $this->expectExceptionMessage('provider failed');
        $executor->execute(
            fn (): never => throw new RuntimeException('provider failed'),
            fn (): AiUsageData => $this->data(),
        );
    }

    private function data(int $durationMs = 1): AiUsageData
    {
        $context = new AiOperationContext(
            correlationId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            attemptId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            stage: 'understand_documents',
            operation: 'ocr',
            attemptOrdinal: 1,
            documentId: 4,
            unitId: 5,
        );

        return new AiUsageData(
            context: $context,
            provider: 'timeweb',
            requestedModel: 'model',
            status: 'succeeded',
            durationMs: $durationMs,
        );
    }
}
