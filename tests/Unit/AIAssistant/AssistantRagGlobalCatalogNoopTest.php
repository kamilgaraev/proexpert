<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Models\EstimatePriceZone;
use App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\Rag\GlobalRagQueue;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class AssistantRagGlobalCatalogNoopTest extends TestCase
{
    public function test_noop_and_timestamp_only_saves_do_not_fan_out_global_catalog_updates(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        $queue = new class {
            public array $calls = [];

            public function queueAfterCommit(string $sourceType, string $entityType, string|int $entityId): void
            {
                $this->calls[] = [$sourceType, $entityType, $entityId];
            }
        };
        $database = new class {
            public function transactionLevel(): int { return 0; }
        };
        $container->instance('log', new class extends AbstractLogger {
            public function log($level, string|\Stringable $message, array $context = []): void {}
        });
        $container->instance('db', $database);
        $container->instance(AssistantIndexingState::class, new AssistantIndexingState);
        $container->instance(GlobalRagQueue::class, $queue);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        try {
            $model = new EstimatePriceZone;
            $model->setDateFormat('Y-m-d H:i:s');
            $model->setRawAttributes(['id' => 42, 'estimate_region_id' => 3, 'name' => 'North'], true);
            $model->wasRecentlyCreated = true;
            $observer = new AssistantRagEntityObserver;

            $observer->saved($model);
            $this->assertSame([], $queue->calls);

            $model->setAttribute('updated_at', '2026-09-30 12:00:00');
            $observer->saved($model);
            $this->assertSame([], $queue->calls);

            $model->setAttribute('name', 'North zone');
            $observer->saved($model);
            $this->assertSame([['organization_reporting', 'estimate_price_zone', 42]], $queue->calls);

            $model->syncOriginal();
            $observer->saved($model);
            $this->assertCount(1, $queue->calls);

            $createdModel = new EstimatePriceZone;
            $createdModel->setRawAttributes(['id' => 43, 'estimate_region_id' => 3, 'name' => 'South']);
            $observer->saved($createdModel);
            $this->assertSame(['organization_reporting', 'estimate_price_zone', 43], $queue->calls[1]);

            $observer->deleted($model);
            $observer->restored($model);
            $this->assertSame([
                ['organization_reporting', 'estimate_price_zone', 42],
                ['organization_reporting', 'estimate_price_zone', 43],
                ['organization_reporting', 'estimate_price_zone', 42],
                ['organization_reporting', 'estimate_price_zone', 42],
            ], $queue->calls);
        } finally {
            Container::setInstance($previousContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }
}
