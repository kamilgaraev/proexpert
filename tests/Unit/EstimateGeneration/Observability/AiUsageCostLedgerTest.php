<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\AiCostCalculator;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiPricingCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageData;
use App\BusinessModules\Addons\EstimateGeneration\Observability\AiUsageStore;
use App\BusinessModules\Addons\EstimateGeneration\Observability\CatalogAiPriceSnapshotResolver;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AiUsageCostLedgerTest extends TestCase
{
    #[Test]
    public function it_records_actual_cost_once_without_budget_reservation(): void
    {
        $resolver = new CatalogAiPriceSnapshotResolver(
            new AiPricingCatalog([
                'vision' => ['timeweb' => ['configured-model' => [[
                    'version' => '2026-08',
                    'effective_at' => '2026-08-01T00:00:00+00:00',
                    'currency' => 'RUB',
                    'input_per_million' => '100.00',
                    'cached_input_per_million' => '0.00',
                    'output_per_million' => '200.00',
                ]]]],
            ]),
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-10T00:00:00+00:00'),
        );
        $context = new AiOperationContext(
            correlationId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7c',
            attemptId: '018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d',
            organizationId: 1,
            projectId: 2,
            sessionId: 3,
            stage: 'understand_documents',
            operation: 'vision',
            attemptOrdinal: 1,
        );
        $usage = new AiUsageData(
            context: $context,
            provider: 'timeweb',
            requestedModel: 'configured-model',
            status: 'succeeded',
            durationMs: 8420,
            usageStatus: 'measured',
            inputTokens: 1200,
            outputTokens: 300,
            priceSnapshot: $resolver->resolve($context, 'timeweb', 'configured-model'),
        );
        $ledger = new class(new AiCostCalculator) implements AiUsageStore
        {
            /** @var array<string, string> */
            public array $costs = [];

            public function __construct(private readonly AiCostCalculator $calculator) {}

            public function record(AiUsageData $data): void
            {
                $cost = $this->calculator->calculate(
                    $data->inputTokens,
                    $data->cachedInputTokens,
                    $data->outputTokens,
                    $data->reasoningTokens,
                    $data->imageCount,
                    $data->pageCount,
                    $data->priceSnapshot?->toArray() ?? [],
                );
                $this->costs[$data->context->attemptId] ??= (string) $cost->amount;
            }
        };

        $ledger->record($usage);
        $ledger->record($usage);

        self::assertSame(['018f47a2-4e5c-7d9a-8b1c-2d3e4f5a6b7d' => '0.18000000'], $ledger->costs);
    }
}
