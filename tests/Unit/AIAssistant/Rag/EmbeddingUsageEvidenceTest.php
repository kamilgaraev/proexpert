<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\Services\Rag\OpenAIRagEmbeddingProvider;
use PHPUnit\Framework\TestCase;

final class EmbeddingUsageEvidenceTest extends TestCase
{
    public function test_missing_usage_preserves_vector_without_fabricating_provider_tokens(): void
    {
        $provider = $this->provider(null);
        self::assertSame([0.1, 0.2], $provider->embed(str_repeat('текст', 100)));
        $usage = $provider->lastUsage();
        self::assertSame(0, $usage['input_tokens']);
        self::assertSame(0, $usage['total_tokens']);
        self::assertFalse($usage['provider_usage_available']);
        self::assertSame('unavailable', $usage['usage_source']);
        self::assertSame(125, $usage['estimated_input_tokens']);
    }

    public function test_provider_usage_is_preserved_and_estimate_is_not_promoted(): void
    {
        $provider = $this->provider((object) ['promptTokens' => 7, 'totalTokens' => 7]);
        $provider->embed(str_repeat('текст', 100));
        self::assertSame(7, $provider->lastUsage()['input_tokens']);
        self::assertTrue($provider->lastUsage()['provider_usage_available']);
        self::assertNull($provider->lastUsage()['estimated_input_tokens']);
        $legacyEstimate = OpenAIRagEmbeddingProvider::usageEvidence(['input_tokens' => 100, 'output_tokens' => 0, 'total_tokens' => 100], 'текст');
        self::assertFalse($legacyEstimate['provider_usage_available']);
        self::assertSame(0, $legacyEstimate['input_tokens']);
    }

    public function test_invalid_numeric_strings_do_not_become_actual_provider_usage(): void
    {
        $provider = $this->provider((object) ['promptTokens' => '7', 'totalTokens' => '7']);
        $provider->embed('текст');
        self::assertFalse($provider->lastUsage()['provider_usage_available']);
    }

    private function provider(?object $usage): OpenAIRagEmbeddingProvider
    {
        $resource = new class($usage) {
            public function __construct(private readonly ?object $usage) {}
            public function create(array $parameters): object
            {
                return (object) ['embeddings' => [(object) ['embedding' => [0.1, 0.2]]], 'usage' => $this->usage];
            }
        };
        $client = new class($resource) {
            public function __construct(private readonly object $resource) {}
            public function embeddings(): object { return $this->resource; }
        };
        return new OpenAIRagEmbeddingProvider($client, 'unit-test-only', 'openai/text-embedding-3-large', 2);
    }
}
