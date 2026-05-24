<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\YandexRagEmbeddingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use Tests\TestCase;

class YandexRagEmbeddingProviderTest extends TestCase
{
    public function test_yandex_provider_uses_document_and_query_models(): void
    {
        Http::fake([
            'https://llm.api.cloud.yandex.net/*' => Http::response([
                'embedding' => ['0.4', '0.5', '0.6'],
                'numTokens' => '12',
                'modelVersion' => 'latest',
            ]),
        ]);

        $provider = new YandexRagEmbeddingProvider(
            apiKey: 'test-key',
            folderId: 'folder-id',
            dimensions: 256
        );

        $documentEmbedding = $provider->embed('Контекст проекта', RagEmbeddingProviderInterface::PURPOSE_DOCUMENT);
        $queryEmbedding = $provider->embed('Риски проекта', RagEmbeddingProviderInterface::PURPOSE_QUERY);

        $this->assertSame([0.4, 0.5, 0.6], $documentEmbedding);
        $this->assertSame([0.4, 0.5, 0.6], $queryEmbedding);
        $this->assertSame('yandex', $provider->provider());
        $this->assertSame('emb://folder-id/text-search-doc/latest', $provider->model());
        $this->assertSame(256, $provider->dimensions());

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://llm.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
                && $request->hasHeader('Authorization', 'Api-Key test-key')
                && $request->hasHeader('x-folder-id', 'folder-id')
                && $request['modelUri'] === 'emb://folder-id/text-search-doc/latest'
                && $request['text'] === 'Контекст проекта'
                && $request['dim'] === '256';
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://llm.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
                && $request->hasHeader('Authorization', 'Api-Key test-key')
                && $request->hasHeader('x-folder-id', 'folder-id')
                && $request['modelUri'] === 'emb://folder-id/text-search-query/latest'
                && $request['text'] === 'Риски проекта'
                && $request['dim'] === '256';
        });
    }

    public function test_yandex_provider_rejects_missing_credentials(): void
    {
        Lang::addLines([
            'ai_assistant.rag_embedding_unavailable' => 'Сервис подготовки контекста временно недоступен.',
        ], 'ru');

        $provider = new YandexRagEmbeddingProvider(
            apiKey: null,
            folderId: null,
            dimensions: 256
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Сервис подготовки контекста временно недоступен.');

        $provider->embed('Контекст проекта');
    }
}
