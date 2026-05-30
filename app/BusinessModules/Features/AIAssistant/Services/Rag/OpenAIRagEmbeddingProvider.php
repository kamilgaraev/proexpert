<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use OpenAI;
use RuntimeException;
use Throwable;

final class OpenAIRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    private ?object $client;

    private ?string $apiKey;

    private string $model;

    private int $dimensions;

    public function __construct(
        ?object $client = null,
        ?string $apiKey = null,
        ?string $model = null,
        ?int $dimensions = null
    ) {
        $this->apiKey = $apiKey ?? $this->configString('ai-assistant.llm.openai.api_key');
        $this->model = $model ?? $this->configString('ai-assistant.rag.embedding_model', 'text-embedding-3-small');
        $this->dimensions = $dimensions ?? $this->configInt('ai-assistant.rag.embedding_dimensions', 1536);
        $this->client = $client ?? $this->makeClient($this->apiKey);
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

        if (str_starts_with($this->model, 'text-embedding-3') && $this->dimensions > 0) {
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
        return 'openai';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    private function makeClient(?string $apiKey): ?object
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return null;
        }

        return OpenAI::client($apiKey);
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
