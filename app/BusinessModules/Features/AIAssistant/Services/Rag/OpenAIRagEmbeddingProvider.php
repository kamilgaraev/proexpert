<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\RagEmbeddingUnavailableException;
use App\BusinessModules\Features\AIAssistant\Services\AssistantHttpRequestOptions;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Exceptions\ErrorException as OpenAIErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\TransporterException;
use RuntimeException;
use Throwable;

final class OpenAIRagEmbeddingProvider implements RagEmbeddingProviderInterface
{
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_BASE_DELAY_MS = 500;
    private const RETRY_MAX_DELAY_MS = 3000;

    private ?object $client;

    private ?object $queryClient;

    private ?string $apiKey;

    private string $model;

    private int $dimensions;

    private ?string $baseUri;

    private string $providerName;

    private int $queryTimeoutSeconds;

    private bool $hasInjectedClient;

    /**
     * @var array<string, mixed>
     */
    private array $lastUsage = [
        'input_tokens' => 0,
        'output_tokens' => 0,
        'total_tokens' => 0,
        'usage_source' => 'unavailable',
        'provider_usage_available' => false,
        'estimated_input_tokens' => null,
    ];
    private array $usageAttempts = [];

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
        $this->queryTimeoutSeconds = max(1, min(10, $this->configInt('ai-assistant.rag.query_embedding_timeout', 6)));
        $this->hasInjectedClient = $client !== null;
        $this->client = $client ?? $this->makeClient($this->apiKey, $this->baseUri);
        $this->queryClient = $client ?? $this->makeClient(
            $this->apiKey,
            $this->baseUri,
            $this->queryTimeoutSeconds
        );
    }

    public function embed(string $text, string $purpose = self::PURPOSE_DOCUMENT): array
    {
        $this->lastUsage = self::usageEvidence(null, $text);
        $this->usageAttempts = [];
        $executionContext = $purpose === self::PURPOSE_QUERY && app()->bound(AssistantRequestExecutionContext::class)
            ? app(AssistantRequestExecutionContext::class)
            : null;
        $client = $purpose === self::PURPOSE_QUERY
            ? ($executionContext !== null && ! $this->hasInjectedClient
                ? $this->makeClient($this->apiKey, $this->baseUri, $this->queryTimeoutSeconds, $executionContext)
                : $this->queryClient)
            : $this->client;

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

        $this->lastUsage = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'usage_source' => 'unavailable',
            'provider_usage_available' => false,
            'estimated_input_tokens' => null,
        ];

        try {
            $response = $this->createEmbeddingWithRetry($embeddings, $parameters, $text, $purpose === self::PURPOSE_QUERY ? 1 : self::RETRY_ATTEMPTS);
        } catch (Throwable $exception) {
            if ($executionContext !== null) {
                $executionContext->assertCanContinue();
            }
            if ($exception instanceof AssistantRequestCancelled || $exception instanceof AssistantRequestDeadlineExceeded) {
                throw $exception;
            }
            throw new RagEmbeddingUnavailableException($this->assistantMessage(
                'ai_assistant.rag_embedding_unavailable',
                'Сервис подготовки контекста временно недоступен.'
            ), 0, $exception);
        }

        $this->lastUsage = $this->usageFromResponse($response, $text);

        $embedding = $response->embeddings[0]->embedding ?? null;
        if (! is_array($embedding)) {
            $lastAttempt = array_key_last($this->usageAttempts);
            if ($lastAttempt !== null) $this->usageAttempts[$lastAttempt]['is_successful'] = false;
            throw new RuntimeException($this->assistantMessage(
                'ai_assistant.rag_embedding_unavailable',
                'Сервис подготовки контекста временно недоступен.'
            ));
        }

        return array_map(static fn (mixed $value): float => (float) $value, array_values($embedding));
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function createEmbeddingWithRetry(object $embeddings, array $parameters, string $text, int $maxAttempts): object
    {
        $lastException = null;
        $callKey = 'embedding:'.bin2hex(random_bytes(16));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $response = $embeddings->create($parameters);
                $this->lastUsage = $this->usageFromResponse($response, $text);
                $this->usageAttempts[] = $this->lastUsage + ['usage_key' => $callKey.':attempt:'.$attempt, 'attempt' => $attempt, 'is_successful' => true];

                return $response;
            } catch (Throwable $exception) {
                if (app()->bound(AssistantRequestExecutionContext::class)) {
                    app(AssistantRequestExecutionContext::class)->assertCanContinue();
                }
                $lastException = $exception;
                $this->lastUsage = $this->usageFromException($exception, $text);
                $this->usageAttempts[] = $this->lastUsage + ['usage_key' => $callKey.':attempt:'.$attempt, 'attempt' => $attempt, 'is_successful' => false,
                    'http_status' => $this->exceptionStatus($exception)];

                if ($attempt >= $maxAttempts || ! $this->shouldRetry($exception)) {
                    throw $exception;
                }

                Log::warning('ai_assistant.rag.openai_embedding_retry', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'provider' => $this->providerName,
                    'model' => $this->model,
                    'exception' => $exception::class,
                    'status' => $this->exceptionStatus($exception),
                ]);

                usleep($this->retryDelayMs($attempt) * 1000);
            }
        }

        throw $lastException;
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof RateLimitException || $exception instanceof TransporterException) {
            return true;
        }

        if ($exception instanceof OpenAIErrorException) {
            $status = $exception->getStatusCode();

            return $status === 429 || $status >= 500;
        }

        $message = mb_strtolower($exception->getMessage(), 'UTF-8');

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'temporarily')
            || str_contains($message, 'try again later')
            || str_contains($message, 'connection');
    }

    private function retryDelayMs(int $attempt): int
    {
        $delay = self::RETRY_BASE_DELAY_MS * (2 ** max(0, $attempt - 1));

        return min($delay, self::RETRY_MAX_DELAY_MS);
    }

    private function exceptionStatus(Throwable $exception): ?int
    {
        if ($exception instanceof OpenAIErrorException) {
            return $exception->getStatusCode();
        }

        if ($exception instanceof RateLimitException) {
            return $exception->response->getStatusCode();
        }

        return null;
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

    /**
     * @return array<string, mixed>
     */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }

    public function usageAttempts(): array
    {
        return $this->usageAttempts;
    }

    private function usageFromException(Throwable $exception, string $text): array
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            $response = $current instanceof OpenAIErrorException || $current instanceof RateLimitException
                ? $current->response : ($current instanceof RequestException ? $current->getResponse() : null);
            if ($response === null) continue;
            try {
                $body = $response->getBody();
                if (! $body->isSeekable()) return self::usageEvidence(null, $text);
                $position = $body->tell();
                $payload = json_decode((string) $body, true);
                $body->seek($position);
            } catch (Throwable) {
                return self::usageEvidence(null, $text);
            }

            return $this->usageFromResponse((object) ['usage' => is_array($payload) ? ($payload['usage'] ?? null) : null], $text);
        }

        return self::usageEvidence(null, $text);
    }

    private function makeClient(
        ?string $apiKey,
        ?string $baseUri,
        int $timeout = 45,
        ?AssistantRequestExecutionContext $executionContext = null
    ): ?object
    {
        if ($apiKey === null || trim($apiKey) === '') {
            return null;
        }

        $httpOptions = $executionContext !== null
            ? AssistantHttpRequestOptions::forContext($timeout, $executionContext)
            : [
                'timeout' => $timeout,
                'connect_timeout' => min(5, $timeout),
            ];

        $factory = OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient(new GuzzleClient($httpOptions));

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

    /**
     * @return array<string, mixed>
     */
    private function usageFromResponse(object $response, string $text): array
    {
        $usage = $response->usage ?? null;
        $inputTokens = $this->usageInt($usage, ['promptTokens', 'prompt_tokens', 'inputTokens', 'input_tokens']);
        $outputTokens = $this->usageInt($usage, ['completionTokens', 'completion_tokens', 'outputTokens', 'output_tokens']);
        $totalTokens = $this->usageInt($usage, ['totalTokens', 'total_tokens']);

        $inputTokens ??= $totalTokens;
        return self::usageEvidence($inputTokens === null ? null : [
            'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens ?? 0,
            'total_tokens' => $totalTokens ?? $inputTokens + ($outputTokens ?? 0),
            'usage_source' => 'provider_response', 'provider_usage_available' => true,
        ], $text);
    }

    public static function usageEvidence(mixed $usage, string $text): array
    {
        $available = is_array($usage) && ($usage['usage_source'] ?? null) === 'provider_response'
            && ($usage['provider_usage_available'] ?? null) === true;
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $field) {
            $available = $available && is_int($usage[$field] ?? null) && $usage[$field] >= 0;
        }
        if ($available && $usage['total_tokens'] === $usage['input_tokens'] + $usage['output_tokens']) {
            return ['input_tokens' => $usage['input_tokens'], 'output_tokens' => $usage['output_tokens'],
                'total_tokens' => $usage['total_tokens'], 'usage_source' => 'provider_response',
                'provider_usage_available' => true, 'estimated_input_tokens' => null];
        }
        return ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
            'usage_source' => 'unavailable', 'provider_usage_available' => false,
            'estimated_input_tokens' => max(1, (int) ceil(mb_strlen($text, 'UTF-8') / 4))];
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function usageInt(mixed $usage, array $keys): ?int
    {
        if (! is_object($usage) && ! is_array($usage)) {
            return null;
        }

        foreach ($keys as $key) {
            $value = is_array($usage)
                ? ($usage[$key] ?? null)
                : ($usage->{$key} ?? null);

            if (is_int($value) && $value >= 0) {
                return $value;
            }
        }

        return null;
    }
}
