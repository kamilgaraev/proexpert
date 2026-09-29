<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Observers\AssistantRagEntityObserver;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class AssistantRagObserverIsolationTest extends TestCase
{
    public function test_index_queue_failure_does_not_fail_business_entity_lifecycle(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        $logger = new class extends AbstractLogger {
            public array $entries = [];
            public function log($level, string|\Stringable $message, array $context = []): void { $this->entries[] = [$level, $message, $context]; }
        };
        $container->instance('log', $logger);
        $container->bind(AssistantIndexingState::class, static function (): never { throw new RuntimeException('index_queue_unavailable'); });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        try {
            $model = new DesignPackage;
            $model->setRawAttributes(['id' => 7, 'organization_id' => 1], true);
            $observer = new AssistantRagEntityObserver;
            foreach (['saved', 'deleted', 'restored'] as $event) { $observer->{$event}($model); }
            $this->assertCount(3, $logger->entries);
            foreach ($logger->entries as [$level, $message, $context]) {
                $this->assertSame('warning', $level);
                $this->assertSame('ai_assistant.rag.entity_queue_failed', $message);
                $this->assertSame('7', $context['entity_id']);
                $this->assertSame(RuntimeException::class, $context['exception_class']);
            }
        } finally {
            Container::setInstance($previousContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }
}
