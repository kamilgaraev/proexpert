<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorderObserver;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use App\BusinessModules\Addons\EstimateGeneration\Pipeline\ProcessingStage;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

final class FailureRecorderTest extends TestCase
{
    #[Test]
    public function recorder_failure_never_masks_the_original_throwable(): void
    {
        $store = new class implements FailureStore
        {
            public function record(FailureData $failure, DateTimeImmutable $seenAt): void
            {
                throw new RuntimeException('database secret');
            }

            public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
            {
                return false;
            }

            public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
            {
                return 0;
            }
        };
        $observer = new class implements FailureRecorderObserver
        {
            /** @var list<array{string, string}> */
            public array $events = [];

            public function recordingFailed(string $failureCode, string $fingerprint): void
            {
                $this->events[] = [$failureCode, $fingerprint];
            }
        };
        $recorder = new FailureRecorder($store, observer: $observer);
        $original = new RuntimeException('private prompt');

        try {
            $recorder->captureAndRethrow($original, $this->context());
            self::fail('Original throwable was not rethrown.');
        } catch (Throwable $actual) {
            self::assertSame($original, $actual);
        }

        self::assertSame('unexpected_internal_failure', $observer->events[0][0]);
        self::assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $observer->events[0][1]);
        self::assertStringNotContainsString('secret', json_encode($observer->events, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('private', json_encode($observer->events, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function resolve_is_tenant_scoped_and_accepts_only_machine_resolution_code(): void
    {
        $store = new class implements FailureStore
        {
            public ?FailureContext $context = null;

            public ?string $code = null;

            public function record(FailureData $failure, DateTimeImmutable $seenAt): void {}

            public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
            {
                $this->context = $context;
                $this->code = $resolutionCode;

                return true;
            }

            public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
            {
                return 0;
            }
        };
        $recorder = new FailureRecorder($store);

        self::assertTrue($recorder->resolve($this->context(), 'sha256:'.str_repeat('a', 64), 'retry_succeeded'));
        self::assertSame(1, $store->context?->organizationId);
        self::assertSame('retry_succeeded', $store->code);

        $this->expectException(\InvalidArgumentException::class);
        $recorder->resolve($this->context(), 'sha256:'.str_repeat('a', 64), 'resolved by user@example.test');
    }

    private function context(): FailureContext
    {
        return new FailureContext(
            organizationId: 1,
            projectId: 10,
            sessionId: 100,
            stage: ProcessingStage::UnderstandDocuments,
            operation: 'process_unit',
            attempt: 1,
            correlationId: '018f4a20-3f4c-7a11-8a22-123456789abc',
            documentId: 1000,
            unitId: 1001,
        );
    }
}
