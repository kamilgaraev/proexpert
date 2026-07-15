<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Pipeline;

use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitAggregateReconciler;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitExecutionContext;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitOutput;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitProcessor;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\DocumentUnitType;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\InMemoryDocumentProcessingUnitStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Documents\ProcessDocumentUnit;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureRecorder;
use App\BusinessModules\Addons\EstimateGeneration\Observability\FailureStore;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PipelineFailureRecoveryTest extends TestCase
{
    #[Test]
    public function unit_failure_is_recorded_once_and_successful_retry_resolves_active_failure(): void
    {
        $units = new InMemoryDocumentProcessingUnitStore;
        $unit = $units->create(1, 2, 3, 4, new DocumentUnitData(DocumentUnitType::Sketch, 1, 'source'));
        $processor = new class implements DocumentUnitProcessor
        {
            public int $calls = 0;

            public function process(DocumentUnitExecutionContext $context): DocumentUnitOutput
            {
                if (++$this->calls === 1) {
                    throw new RuntimeException('Bearer private-drawing-token');
                }

                return new DocumentUnitOutput('output', 'recognized');
            }
        };
        $reconciler = new class implements DocumentUnitAggregateReconciler
        {
            public function reconcile(int $documentId, string $sourceVersion): void {}
        };
        $failures = new class implements FailureStore
        {
            /** @var list<FailureData> */
            public array $recorded = [];

            public int $resolved = 0;

            public function record(FailureData $failure, DateTimeImmutable $seenAt): void
            {
                $this->recorded[] = $failure;
            }

            public function resolve(FailureContext $context, string $fingerprint, string $resolutionCode, DateTimeImmutable $resolvedAt): bool
            {
                return false;
            }

            public function resolveActive(FailureContext $context, string $resolutionCode, DateTimeImmutable $resolvedAt): int
            {
                \PHPUnit\Framework\Assert::assertSame('retry_succeeded', $resolutionCode);
                \PHPUnit\Framework\Assert::assertSame(4, $context->documentId);

                return ++$this->resolved;
            }
        };
        $useCase = new ProcessDocumentUnit(
            $units,
            $processor,
            $reconciler,
            failureRecorder: new FailureRecorder($failures),
        );

        try {
            $useCase->handle($unit->id, 'source');
            self::fail('First attempt must fail.');
        } catch (RuntimeException $error) {
            self::assertSame('Bearer private-drawing-token', $error->getMessage());
        }
        $useCase->handle($unit->id, 'source');

        self::assertCount(1, $failures->recorded);
        self::assertSame('unexpected_internal_failure', $failures->recorded[0]->code);
        self::assertStringNotContainsString('private', json_encode($failures->recorded[0]->safeContext, JSON_THROW_ON_ERROR));
        self::assertSame(1, $failures->resolved);
    }
}
