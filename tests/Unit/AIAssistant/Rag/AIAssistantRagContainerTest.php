<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Services\AIAssistantService;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagPromptContextBuilder;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagRetriever;
use App\BusinessModules\Features\AIAssistant\Services\Rag\YandexRagEmbeddingProvider;
use ReflectionClass;
use Tests\TestCase;

class AIAssistantRagContainerTest extends TestCase
{
    public function test_container_injects_rag_dependencies_into_assistant_service(): void
    {
        $service = app(AIAssistantService::class);
        $reflection = new ReflectionClass($service);

        $ragRetriever = $reflection->getProperty('ragRetriever');
        $ragRetriever->setAccessible(true);
        $ragPromptContextBuilder = $reflection->getProperty('ragPromptContextBuilder');
        $ragPromptContextBuilder->setAccessible(true);

        $this->assertInstanceOf(RagRetriever::class, $ragRetriever->getValue($service));
        $this->assertInstanceOf(RagPromptContextBuilder::class, $ragPromptContextBuilder->getValue($service));
    }

    public function test_container_resolves_yandex_rag_embedding_provider_by_default(): void
    {
        $provider = app(RagEmbeddingProviderInterface::class);

        $this->assertInstanceOf(YandexRagEmbeddingProvider::class, $provider);
    }
}
