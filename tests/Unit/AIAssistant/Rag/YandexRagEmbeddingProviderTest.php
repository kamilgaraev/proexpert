<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use App\BusinessModules\Features\AIAssistant\Services\Rag\YandexRagEmbeddingProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class YandexRagEmbeddingProviderTest extends TestCase
{
    public function test_yandex_provider_uses_document_and_query_models(): void
    {
        Http::fake([
            'https://ai.api.cloud.yandex.net/*' => Http::response([
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
            return $request->url() === 'https://ai.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
                && $request->hasHeader('Authorization', 'Api-Key test-key')
                && $request->hasHeader('x-folder-id', 'folder-id')
                && $request['modelUri'] === 'emb://folder-id/text-search-doc/latest'
                && $request['text'] === 'Контекст проекта'
                && ! isset($request['dim']);
        });

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://ai.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
                && $request->hasHeader('Authorization', 'Api-Key test-key')
                && $request->hasHeader('x-folder-id', 'folder-id')
                && $request['modelUri'] === 'emb://folder-id/text-search-query/latest'
                && $request['text'] === 'Риски проекта'
                && ! isset($request['dim']);
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

    public function test_yandex_provider_retries_rate_limited_response(): void
    {
        Http::fake([
            'https://ai.api.cloud.yandex.net/*' => Http::sequence()
                ->push(['error' => 'too many requests'], 429)
                ->push(['embedding' => ['0.1', '0.2', '0.3']], 200),
        ]);

        $log = Log::spy();

        $provider = new YandexRagEmbeddingProvider(
            apiKey: 'test-key',
            folderId: 'folder-id',
            dimensions: 256
        );

        $embedding = $provider->embed('Контекст проекта');

        $this->assertSame([0.1, 0.2, 0.3], $embedding);
        Http::assertSentCount(2);
        $log->shouldNotHaveReceived('warning');
    }

    public function test_yandex_provider_logs_failed_response_without_sensitive_request_data(): void
    {
        Http::fake([
            'https://ai.api.cloud.yandex.net/*' => Http::response(['message' => 'forbidden'], 403),
        ]);

        $log = Log::spy();

        $provider = new YandexRagEmbeddingProvider(
            apiKey: 'secret-key',
            folderId: 'folder-id',
            dimensions: 256
        );

        try {
            $provider->embed('sensitive project context');
            $this->fail('Expected Yandex embedding failure.');
        } catch (RuntimeException) {
            $this->assertTrue(true);
        }

        $log->shouldHaveReceived('warning')
            ->once()
            ->with('ai_assistant.rag.yandex_embedding_failed', Mockery::on(static function (array $context): bool {
                $encodedContext = json_encode($context, JSON_THROW_ON_ERROR);

                return $context['status'] === 403
                    && $context['endpoint_host'] === 'ai.api.cloud.yandex.net'
                    && $context['model_uri'] === 'emb://folder-id/text-search-doc/latest'
                    && $context['response_body'] === '{"message":"forbidden"}'
                    && ! str_contains($encodedContext, 'secret-key')
                    && ! str_contains($encodedContext, 'sensitive project context');
            }));
    }
}
