<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class YandexRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    private ?string $apiKey;

    private ?string $folderId;

    private ?string $documentModelUri;

    private ?string $queryModelUri;

    private int $dimensions;

    private string $endpoint;

    public function __construct(
        ?string $apiKey = null,
        ?string $folderId = null,
        ?string $documentModelUri = null,
        ?string $queryModelUri = null,
        ?int $dimensions = null,
        ?string $endpoint = null
    ) {
        $this->apiKey = $apiKey ?? $this->configString('ai-assistant.llm.yandex.api_key');
        $this->folderId = $folderId ?? $this->configString('ai-assistant.llm.yandex.folder_id');
        $this->documentModelUri = $documentModelUri
            ?? $this->configString('ai-assistant.rag.embedding_document_model_uri')
            ?? $this->defaultModelUri(self::PURPOSE_DOCUMENT);
        $this->queryModelUri = $queryModelUri
            ?? $this->configString('ai-assistant.rag.embedding_query_model_uri')
            ?? $this->defaultModelUri(self::PURPOSE_QUERY);
        $this->dimensions = $dimensions ?? $this->configInt('ai-assistant.rag.embedding_dimensions', 256);
        $this->endpoint = $endpoint
            ?? $this->configString(
                'ai-assistant.rag.embedding_endpoint',
                'https://llm.api.cloud.yandex.net/foundationModels/v1/textEmbedding'
            );
    }

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $modelUri = $this->modelUri($purpose);

        if ($this->apiKey === null || trim($this->apiKey) === '' || $modelUri === null || trim($modelUri) === '') {
            throw new RuntimeException($this->assistantMessage());
        }

        $payload = [
            'modelUri' => $modelUri,
            'text' => $text,
        ];

        if ($this->dimensions > 0) {
            $payload['dim'] = (string) $this->dimensions;
        }

        $headers = [
            'Authorization' => 'Api-Key '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($this->folderId !== null && trim($this->folderId) !== '') {
            $headers['x-folder-id'] = $this->folderId;
        }

        $response = Http::withHeaders($headers)->timeout(60)->post($this->endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException($this->assistantMessage());
        }

        $embedding = $response->json('embedding');
        if (! is_array($embedding)) {
            throw new RuntimeException($this->assistantMessage());
        }

        return array_map(static fn (mixed $value): float => (float) $value, array_values($embedding));
    }

    public function provider(): string
    {
        return 'yandex';
    }

    public function model(): string
    {
        return $this->documentModelUri ?? '';
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function modelUri(string $purpose): ?string
    {
        return $purpose === self::PURPOSE_QUERY ? $this->queryModelUri : $this->documentModelUri;
    }

    private function defaultModelUri(string $purpose): ?string
    {
        if ($this->folderId === null || trim($this->folderId) === '') {
            return null;
        }

        $model = $purpose === self::PURPOSE_QUERY ? 'text-search-query' : 'text-search-doc';

        return sprintf('emb://%s/%s/latest', $this->folderId, $model);
    }

    private function configString(string $key, ?string $default = null): ?string
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_string($value) && trim($value) !== '' ? $value : $default;
    }

    private function configInt(string $key, int $default): int
    {
        try {
            $value = config($key, $default);
        } catch (Throwable) {
            return $default;
        }

        return is_numeric($value) ? (int) $value : $default;
    }

    private function assistantMessage(): string
    {
        try {
            return trans_message('ai_assistant.rag_embedding_unavailable');
        } catch (Throwable) {
            return 'Сервис подготовки контекста временно недоступен.';
        }
    }
}
