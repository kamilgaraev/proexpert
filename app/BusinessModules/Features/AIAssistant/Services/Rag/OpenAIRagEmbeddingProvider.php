<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use RuntimeException;
use Throwable;

final class OpenAIRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    private ?object $client;

    private ?string $apiKey;

    private string $model;

    private int $dimensions;

    private ?string $baseUri;

    private string $providerName;

    public function __construct(
        ?object $client = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?int $dimensions = null,
        ?string $baseUri = null,
        ?string $providerName = null
    ) {
        $this->apiKey = $apiKey ?? $this->configString(
            'ai-assistant.rag.embedding_api_key',
            $this->configString('ai-assistant.llm.openai.api_key')
        );
        $this->model = $model ?? $this->configString('ai-assistant.rag.embedding_model', 'text-embedding-3-small');
        $this->dimensions = $dimensions ?? $this->configInt('ai-assistant.rag.embedding_dimensions', 1536);
        $this->baseUri = $baseUri ?? $this->configString('ai-assistant.rag.embedding_base_uri');
        $this->providerName = $providerName ?? 'openai';
        $this->client = $client ?? $this->makeClient($this->apiKey, $this->baseUri);
    }

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $client = $this->client;

        if (! is_object($client) || ! method_exists($client, 'embeddings')) {
            throw new RuntimeException($this->assistantMessage(
                'ai_assistant.rag_embedding_unavailable',
                'Сервис подготовки контекста временно недоступен.'
            ));
        }

        $embeddings = $client->embeddings();
        if (! is_object($embeddings) || ! method_exists($embeddings, 'create')) {
            throw new RuntimeException($this->assistantMessage(
                'ai_assistant.rag_embedding_unavailable',
                'Сервис подготовки контекста временно недоступен.'
            ));
        }

        $parameters = [
            'model' => $this->model,
            'input' => $text,
        ];

        if (str_contains($this->model, 'text-embedding-3') && $this->dimensions > 0) {
            $parameters['dimensions'] = $this->dimensions;
        }

        $response = $embeddings->create($parameters);

        $embedding = $response->embeddings[0]->embedding ?? null;
        if (! is_array($embedding)) {
            throw new RuntimeException($this->assistantMessage(
                'ai_assistant.rag_embedding_unavailable',
                'Сервис подготовки контекста временно недоступен.'
            ));
        }

        return array_map(static fn (mixed $value): float => (float) $value, array_values($embedding));
    }

    public function provider(): string
    {
        return $this->providerName;
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function makeClient(?string $apiKey, ?string $baseUri): ?object
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return null;
        }

        $factory = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient(new GuzzleClient([
                'timeout' => 45,
                'connect_timeout' => 5,
            ]));

        if ($baseUri !== null && trim($baseUri) !== '') {
            $factory = $factory->withBaseUri($baseUri);
        }

        return $factory->make();
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

    private function assistantMessage(string $key, string $fallback): string
    {
        try {
            return trans_message($key);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
