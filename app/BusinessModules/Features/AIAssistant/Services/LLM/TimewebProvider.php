<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\Services\Logging\LoggingService;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use RuntimeException;
use Throwable;

final class TimewebProvider implements LLMProviderInterface
{
    private string $apiKey;

    private string $baseUri;

    private string $model;

    private int $maxTokens;

    private float $temperature;

    private float $timeout;

    public function __construct(private readonly LoggingService $logging)
    {
        $this->apiKey = (string) config('ai-assistant.llm.timeweb.api_key', '');
        $this->baseUri = (string) config('ai-assistant.llm.timeweb.base_uri', 'https://api.timeweb.ai/v1');
        $this->model = (string) config('ai-assistant.llm.timeweb.model', 'gemini/gemini-3.1-flash-lite');
        $this->maxTokens = (int) config('ai-assistant.llm.timeweb.max_tokens', 2000);
        $this->temperature = (float) config('ai-assistant.llm.timeweb.temperature', 0.7);
        $this->timeout = (float) config('ai-assistant.llm.timeweb.timeout', 25);
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Timeweb AI Gateway API key not configured');
        }

        $profile = $this->profile($options);
        $profileConfig = $this->profileConfig($profile);
        $models = $this->models($options, $profileConfig);
        $maxTokens = (int) ($options['max_tokens'] ?? $profileConfig['max_tokens'] ?? $this->maxTokens);
        $temperature = (float) ($options['temperature'] ?? $profileConfig['temperature'] ?? $this->temperature);
        $timeout = $this->positiveFloat($options['timeout'] ?? $profileConfig['timeout'] ?? $this->timeout, $this->timeout);
        $lastException = null;

        foreach ($models as $attempt => $model) {
            $requestPayload = [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
            ];

            foreach (['tools', 'tool_choice', 'response_format'] as $optionKey) {
                if (array_key_exists($optionKey, $options)) {
                    $requestPayload[$optionKey] = $options[$optionKey];
                }
            }

            try {
                $this->logging->technical('ai.timeweb.request', [
                    'profile' => $profile,
                    'model' => $model,
                    'attempt' => $attempt + 1,
                    'messages_count' => count($messages),
                    'max_tokens' => $maxTokens,
                    'has_tools' => !empty($options['tools']),
                    'timeout' => $timeout,
                ]);

                $startTime = microtime(true);
                $response = $this->makeClient($timeout)->chat()->create($requestPayload);
                $duration = microtime(true) - $startTime;

                $result = $this->parseResponse($response, $model);
                $result['profile'] = $profile;
                $result['route_attempt'] = $attempt + 1;
                $result['route_fallback'] = $attempt > 0;

                $this->logging->technical('ai.timeweb.success', [
                    'profile' => $profile,
                    'model' => $result['model'],
                    'tokens_used' => $result['tokens_used'],
                    'duration_ms' => round($duration * 1000, 2),
                ]);

                return $result;
            } catch (Throwable $exception) {
                $lastException = $exception;

                $this->logging->technical('ai.timeweb.model_failed', [
                    'profile' => $profile,
                    'model' => $model,
                    'attempt' => $attempt + 1,
                    'error' => $exception->getMessage(),
                    'exception_class' => get_class($exception),
                ], 'warning');
            }
        }

        if ($lastException instanceof Throwable) {
            throw $lastException;
        }

        throw new RuntimeException("No Timeweb models configured for profile '{$profile}'");
    }

    public function countTokens(string $text): int
    {
        return (int) (mb_strlen($text, 'UTF-8') / 4);
    }

    public function isAvailable(): bool
    {
        return trim($this->apiKey) !== '' && trim($this->baseUri) !== '' && $this->model !== '';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function makeClient(float $timeout): object
    {
        $connectTimeout = max(1.0, min(5.0, $timeout));

        return OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withBaseUri($this->baseUri)
            ->withHttpClient(new GuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ]))
            ->make();
    }

    private function parseResponse(object $response, string $requestedModel): array
    {
        $message = $response->choices[0]->message ?? null;
        if (!is_object($message)) {
            throw new RuntimeException('Invalid Timeweb response format: no message');
        }

        $usage = $response->usage ?? null;
        $promptTokens = is_object($usage) ? (int) ($usage->promptTokens ?? 0) : 0;
        $completionTokens = is_object($usage) ? (int) ($usage->completionTokens ?? 0) : 0;
        $totalTokens = is_object($usage) ? (int) ($usage->totalTokens ?? ($promptTokens + $completionTokens)) : 0;

        $result = [
            'content' => (string) ($message->content ?? ''),
            'role' => (string) ($message->role ?? 'assistant'),
            'tokens_used' => $totalTokens,
            'input_tokens' => $promptTokens,
            'output_tokens' => $completionTokens,
            'model' => (string) ($response->model ?? $requestedModel),
            'provider' => 'timeweb',
            'finish_reason' => $response->choices[0]->finishReason ?? 'stop',
        ];

        if (!empty($message->toolCalls)) {
            $result['tool_calls'] = array_map(static function (object $toolCall): array {
                return [
                    'id' => $toolCall->id,
                    'type' => $toolCall->type,
                    'function' => [
                        'name' => $toolCall->function->name,
                        'arguments' => $toolCall->function->arguments,
                    ],
                ];
            }, $message->toolCalls);
        }

        return $result;
    }

    private function positiveFloat(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $normalized = (float) $value;

        return $normalized > 0 ? $normalized : $default;
    }

    private function profile(array $options): string
    {
        $profile = trim((string) ($options['profile'] ?? config(
            'ai-assistant.llm.timeweb.default_profile',
            'assistant'
        )));

        return $profile !== '' ? $profile : 'assistant';
    }

    /**
     * @return array<string, mixed>
     */
    private function profileConfig(string $profile): array
    {
        $config = config("ai-assistant.llm.timeweb.profiles.{$profile}", []);

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<int, string>
     */
    private function models(array $options, array $profileConfig): array
    {
        if (isset($options['model']) && trim((string) $options['model']) !== '') {
            return [trim((string) $options['model'])];
        }

        $models = $profileConfig['models'] ?? [$this->model];

        if (is_string($models)) {
            $models = explode(',', $models);
        }

        if (!is_array($models)) {
            $models = [$this->model];
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $model): string => trim((string) $model),
            $models
        )));

        return $normalized !== [] ? $normalized : [$this->model];
    }
}
