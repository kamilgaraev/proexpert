<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class OpenAIRagEmbeddingProviderTest extends TestCase
{
    public function test_contract_returns_embedding_metadata(): void
    {
        $provider = new FakeRagEmbeddingProvider([0.1, 0.2, 0.3]);

        $this->assertSame([0.1, 0.2, 0.3], $provider->embed('тестовый фрагмент'));
        $this->assertSame('fake', $provider->provider());
        $this->assertSame('fake-model', $provider->model());
        $this->assertSame(3, $provider->dimensions());
    }

    public function test_openai_provider_creates_embedding_with_configured_model(): void
    {
        $client = new FakeOpenAIEmbeddingClient([0.4, 0.5, 0.6]);
        $provider = new OpenAIRagEmbeddingProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'text-embedding-3-small',
            dimensions: 3
        );

        $this->assertSame([0.4, 0.5, 0.6], $provider->embed('Контекст проекта'));
        $this->assertSame('openai', $provider->provider());
        $this->assertSame('text-embedding-3-small', $provider->model());
        $this->assertSame(3, $provider->dimensions());
        $this->assertSame([
            'model' => 'text-embedding-3-small',
            'input' => 'Контекст проекта',
        ], $client->embeddings->lastParameters);
    }

    public function test_openai_provider_rejects_unavailable_client(): void
    {
        $provider = new OpenAIRagEmbeddingProvider(
            client: null,
            apiKey: null,
            model: 'text-embedding-3-small',
            dimensions: 1536
        );

        $this->expectException(RuntimeException::class);
        $provider->embed('Контекст проекта');
    }
}

final class FakeRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    /**
     * @param  array<int, float>  $embedding
     */
    public function __construct(private readonly array $embedding)
    {
    }

    public function embed(string $text): array
    {
        return $this->embedding;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return 'fake-model';
    }

    public function dimensions(): int
    {
        return count($this->embedding);
    }
}

final class FakeOpenAIEmbeddingClient
{
    public FakeOpenAIEmbeddingsResource $embeddings;

    /**
     * @param  array<int, float>  $embedding
     */
    public function __construct(array $embedding)
    {
        $this->embeddings = new FakeOpenAIEmbeddingsResource($embedding);
    }

    public function embeddings(): FakeOpenAIEmbeddingsResource
    {
        return $this->embeddings;
    }
}

final class FakeOpenAIEmbeddingsResource
{
    /**
     * @var array<string, mixed>
     */
    public array $lastParameters = [];

    /**
     * @param  array<int, float>  $embedding
     */
    public function __construct(private readonly array $embedding)
    {
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function create(array $parameters): object
    {
        $this->lastParameters = $parameters;

        return (object) [
            'embeddings' => [
                (object) [
                    'embedding' => $this->embedding,
                ],
            ],
        ];
    }
}
