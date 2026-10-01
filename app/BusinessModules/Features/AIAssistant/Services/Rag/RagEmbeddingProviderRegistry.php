<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use InvalidArgumentException;

final class RagEmbeddingProviderRegistry
{
    private array $providers = [];

    public function __construct(private readonly RagEmbeddingProviderInterface $legacyProvider) {}

    public function newIndexProvider(): RagEmbeddingProviderInterface
    {
        $provider = (string) config('ai-assistant.rag.new_index_embedding_provider', 'timeweb');
        $model = (string) config('ai-assistant.rag.new_index_embedding_model', 'dashscope/text-embedding-v4');
        $dimensions = (int) config('ai-assistant.rag.new_index_embedding_dimensions', 256);

        return $this->forProfile($provider, $model, $dimensions)
            ?? throw new InvalidArgumentException('ai_rag_embedding_profile_invalid');
    }

    public function forProfile(string $provider, string $model, int $dimensions): ?RagEmbeddingProviderInterface
    {
        if ($provider === $this->legacyProvider->provider() && $model === $this->legacyProvider->model()
            && $dimensions === $this->legacyProvider->dimensions()) {
            return $this->legacyProvider;
        }

        $supportedModels = match ($provider) {
            'timeweb' => ['openai/text-embedding-3-large', 'openai/text-embedding-3-small', 'dashscope/text-embedding-v4'],
            'openai' => ['text-embedding-3-large', 'text-embedding-3-small'],
            default => [],
        };
        if ($dimensions !== 256 || ! in_array($model, $supportedModels, true)) {
            return null;
        }

        $key = $provider.'|'.$model.'|'.$dimensions;
        if (isset($this->providers[$key])) {
            return $this->providers[$key];
        }

        $useLegacyConnection = $provider === $this->legacyProvider->provider();
        $apiKey = $useLegacyConnection ? config('ai-assistant.rag.embedding_api_key') : null;
        $baseUri = $useLegacyConnection ? config('ai-assistant.rag.embedding_base_uri') : null;

        return $this->providers[$key] = new OpenAIRagEmbeddingProvider(
            apiKey: $apiKey ?: config('ai-assistant.llm.'.$provider.'.api_key'),
            model: $model,
            dimensions: $dimensions,
            baseUri: $baseUri ?: config('ai-assistant.llm.'.$provider.'.base_uri'),
            providerName: $provider,
        );
    }

    public function configuredProfiles(): array
    {
        $newProvider = $this->newIndexProvider();
        $profiles = [];
        foreach ([$this->legacyProvider, $newProvider] as $provider) {
            $key = $provider->provider().'|'.$provider->model().'|'.$provider->dimensions();
            $profiles[$key] = ['provider' => $provider->provider(), 'model' => $provider->model(),
                'dimensions' => $provider->dimensions()];
        }

        return array_values($profiles);
    }
}
