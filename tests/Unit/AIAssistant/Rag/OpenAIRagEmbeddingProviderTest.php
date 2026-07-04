<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingUnavailableException;
use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use App\BusinessModules\Features\AIAssistant\Services\Rag\RagEmbeddingProviderInterface;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use Throwable;
use Tests\TestCase;

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
            'input_tokens' => 14,
            'output_tokens' => 0,
            'total_tokens' => 14,
        ], $provider->lastUsage());
        $this->assertSame([
            'model' => 'text-embedding-3-small',
            'input' => 'Контекст проекта',
            'dimensions' => 3,
        ], $client->embeddings->lastParameters);
    }

    public function test_openai_provider_passes_configured_dimensions_for_text_embedding_3_models(): void
    {
        $client = new FakeOpenAIEmbeddingClient([0.4, 0.5, 0.6]);
        $provider = new OpenAIRagEmbeddingProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'text-embedding-3-small',
            dimensions: 256
        );

        $provider->embed('Контекст проекта');

        $this->assertSame([
            'model' => 'text-embedding-3-small',
            'input' => 'Контекст проекта',
            'dimensions' => 256,
        ], $client->embeddings->lastParameters);
    }

    public function test_openai_provider_rejects_unavailable_client(): void
    {
        Lang::addLines([
            'ai_assistant.rag_embedding_unavailable' => 'Переведенное сообщение о недоступности подготовки контекста.',
        ], 'ru');

        $provider = new OpenAIRagEmbeddingProvider(
            client: null,
            apiKey: null,
            model: 'text-embedding-3-small',
            dimensions: 1536
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Переведенное сообщение о недоступности подготовки контекста.');

        $provider->embed('Контекст проекта');
    }

    public function test_openai_provider_converts_client_failure_to_unavailable_exception(): void
    {
        Lang::addLines([
            'ai_assistant.rag_embedding_unavailable' => 'Переведенное сообщение о недоступности подготовки контекста.',
        ], 'ru');

        $clientException = new RuntimeException('temporary provider failure');
        $provider = new OpenAIRagEmbeddingProvider(
            client: new FakeOpenAIEmbeddingClient([0.4], $clientException),
            apiKey: 'test-key',
            model: 'text-embedding-3-small',
            dimensions: 3
        );

        try {
            $provider->embed('project context');
            $this->fail('Expected RAG embedding unavailable exception.');
        } catch (RagEmbeddingUnavailableException $exception) {
            $this->assertSame('Переведенное сообщение о недоступности подготовки контекста.', $exception->getMessage());
            $this->assertSame($clientException, $exception->getPrevious());
        }
    }

    public function test_openai_provider_retries_transient_embedding_failure(): void
    {
        $client = new FakeOpenAIEmbeddingClient(
            [0.7, 0.8, 0.9],
            [new RuntimeException('Operation timed out after 45003 milliseconds')]
        );
        $provider = new OpenAIRagEmbeddingProvider(
            client: $client,
            apiKey: 'test-key',
            model: 'text-embedding-3-small',
            dimensions: 3
        );

        $this->assertSame([0.7, 0.8, 0.9], $provider->embed('project context'));
        $this->assertSame(2, $client->embeddings->attempts);
    }
}

final class FakeRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    /**
     * @param  array<int, float>  $embedding
     */
    public function __construct(private readonly array $embedding) {}

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
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
     * @param  Throwable|array<int, Throwable>|null  $exception
     */
    public function __construct(array $embedding, Throwable|array|null $exception = null)
    {
        $this->embeddings = new FakeOpenAIEmbeddingsResource($embedding, $exception);
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

    public int $attempts = 0;

    /**
     * @param  array<int, float>  $embedding
     * @param  Throwable|array<int, Throwable>|null  $exception
     */
    public function __construct(
        private readonly array $embedding,
        Throwable|array|null $exception = null
    ) {
        $this->exceptions = is_array($exception)
            ? array_values($exception)
            : ($exception !== null ? [$exception] : []);
    }

    /**
     * @var array<int, Throwable>
     */
    private array $exceptions;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function create(array $parameters): object
    {
        $this->attempts++;
        $this->lastParameters = $parameters;

        if ($this->exceptions !== []) {
            throw array_shift($this->exceptions);
        }

        return (object) [
            'embeddings' => [
                (object) [
                    'embedding' => $this->embedding,
                ],
            ],
            'usage' => (object) [
                'promptTokens' => 14,
                'totalTokens' => 14,
            ],
        ];
    }
}
