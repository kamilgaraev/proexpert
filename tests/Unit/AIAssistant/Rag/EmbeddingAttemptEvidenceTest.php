<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingUnavailableException;
use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use OpenAI\Exceptions\ErrorException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class EmbeddingAttemptEvidenceTest extends TestCase
{
    protected function setUp(): void
    {
        Log::swap(new class {
            public function warning(string $message, array $context = []): void {}
        });
    }

    public function test_sdk_http_failure_preserves_actual_usage_without_raw_error_text(): void
    {
        $response = new Response(400, [], json_encode(['error' => ['message' => 'secret-error-body'], 'usage' => ['prompt_tokens' => 23, 'total_tokens' => 23]], JSON_THROW_ON_ERROR));
        $resource = new class($response) {
            public function __construct(private Response $response) {}
            public function create(array $parameters): object { throw new ErrorException(['message' => 'secret-error-body'], $this->response); }
        };
        $provider = $this->provider($resource);
        try { $provider->embed('synthetic'); self::fail('Failure expected'); } catch (RagEmbeddingUnavailableException) {}
        $attempts = $provider->usageAttempts();
        self::assertCount(1, $attempts);
        self::assertSame(23, $attempts[0]['input_tokens']);
        self::assertTrue($attempts[0]['provider_usage_available']);
        self::assertFalse($attempts[0]['is_successful']);
        self::assertSame(400, $attempts[0]['http_status']);
        self::assertStringNotContainsString('secret-error-body', json_encode($attempts, JSON_THROW_ON_ERROR));
    }

    public function test_retry_keeps_each_actual_provider_attempt_and_distinct_keys(): void
    {
        $resource = new class {
            private int $calls = 0;
            public function create(array $parameters): object
            {
                if (++$this->calls === 1) throw new ErrorException(['message' => 'retry'], new Response(503, [], json_encode(['usage' => ['prompt_tokens' => 17, 'total_tokens' => 17]], JSON_THROW_ON_ERROR)));
                return (object) ['usage' => (object) ['promptTokens' => 19, 'totalTokens' => 19], 'embeddings' => [(object) ['embedding' => [1.0]]]];
            }
        };
        $provider = $this->provider($resource);
        self::assertSame([1.0], $provider->embed('synthetic'));
        $attempts = $provider->usageAttempts();
        self::assertCount(2, $attempts);
        self::assertSame([17, 19], array_column($attempts, 'input_tokens'));
        self::assertSame([false, true], array_column($attempts, 'is_successful'));
        self::assertNotSame($attempts[0]['usage_key'], $attempts[1]['usage_key']);
        self::assertSame(19, $provider->lastUsage()['input_tokens']);
    }

    public function test_unknown_failure_has_unavailable_evidence_and_never_reuses_previous_usage(): void
    {
        $resource = new class {
            private int $calls = 0;
            public function create(array $parameters): object
            {
                if (++$this->calls > 1) throw new RuntimeException('synthetic failure');
                return (object) ['usage' => (object) ['promptTokens' => 19, 'totalTokens' => 19], 'embeddings' => [(object) ['embedding' => [1.0]]]];
            }
        };
        $provider = $this->provider($resource);
        $provider->embed('first');
        try { $provider->embed('second'); self::fail('Failure expected'); } catch (RagEmbeddingUnavailableException) {}
        self::assertCount(1, $provider->usageAttempts());
        self::assertFalse($provider->usageAttempts()[0]['provider_usage_available']);
        self::assertSame('unavailable', $provider->usageAttempts()[0]['usage_source']);
        self::assertSame(0, $provider->lastUsage()['input_tokens']);
    }

    public function test_invalid_success_payload_keeps_actual_usage_but_marks_attempt_failed(): void
    {
        $resource = new class {
            public function create(array $parameters): object { return (object) ['usage' => ['prompt_tokens' => 7, 'total_tokens' => 7], 'embeddings' => []]; }
        };
        $provider = $this->provider($resource);
        try { $provider->embed('synthetic'); self::fail('Invalid payload expected'); } catch (RuntimeException) {}
        self::assertSame(7, $provider->usageAttempts()[0]['input_tokens']);
        self::assertTrue($provider->usageAttempts()[0]['provider_usage_available']);
        self::assertFalse($provider->usageAttempts()[0]['is_successful']);
    }

    private function provider(object $resource): OpenAIRagEmbeddingProvider
    {
        $client = new class($resource) {
            public function __construct(private object $resource) {}
            public function embeddings(): object { return $this->resource; }
        };

        return new OpenAIRagEmbeddingProvider($client, 'synthetic-key', 'openai/text-embedding-3-large', 1, 'https://api.timeweb.ai/v1', 'timeweb');
    }
}
