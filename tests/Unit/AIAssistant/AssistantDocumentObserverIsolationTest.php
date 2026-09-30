<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant;

use App\BusinessModules\Features\AIAssistant\Models\AIAssistantDocument;
use App\BusinessModules\Features\AIAssistant\Services\AssistantIndexingState;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagIndexingCoordinator;
use App\Observers\AssistantDocumentIndexObserver;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;

final class AssistantDocumentObserverIsolationTest extends TestCase
{
    public function test_queue_failure_keeps_document_lifecycle_successful_and_records_retry_diagnostic(): void
    {
        $previousContainer = Container::getInstance();
        $previousFacade = Facade::getFacadeApplication();
        $container = new Container;
        $logger = new class extends AbstractLogger {
            public array $entries = [];
            public function log($level, string|\Stringable $message, array $context = []): void { $this->entries[] = [$level, $message, $context]; }
        };
        $container->instance('log', $logger);
        $container->instance(AssistantIndexingState::class, new AssistantIndexingState);
        $container->bind(RagIndexingCoordinator::class, static function (): never { throw new RuntimeException('queue_unavailable'); });
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        try {
            $document = new AIAssistantDocument;
            $document->setRawAttributes(['id' => 7, 'organization_id' => 1, 'project_id' => null], true);
            (new AssistantDocumentIndexObserver)->deleted($document);
            $this->assertCount(1, $logger->entries);
            $this->assertSame('warning', $logger->entries[0][0]);
            $this->assertSame('ai_assistant.document.index_pending_failed', $logger->entries[0][1]);
            $this->assertSame(RuntimeException::class, $logger->entries[0][2]['exception_class']);
        } finally {
            Container::setInstance($previousContainer);
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication($previousFacade);
        }
    }
}
