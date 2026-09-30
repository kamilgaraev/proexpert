<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\Support\AI\LunaModelPolicy;
use App\Support\AI\TokenCounter;
use App\Support\AI\TokenBudgetService;
use App\Services\Logging\LoggingService;
use App\BusinessModules\Features\AIAssistant\Services\AssistantHttpRequestOptions;
use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestExecutionContext;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use OpenAI;

final class OpenAIProvider implements LLMProviderInterface
{
    protected LoggingService $logging;
    protected string $apiKey;
    protected ?string $baseUri;
    protected string $model;
    protected int $maxTokens;
    protected float $temperature;
    protected float $timeout;

    public function __construct(LoggingService $logging, private readonly ?ClientInterface $httpClient = null)
    {
        $this->logging = $logging;
        $this->apiKey = (string) config('ai-assistant.llm.openai.api_key', config('ai-assistant.openai_api_key', ''));
        $this->baseUri = config('ai-assistant.llm.openai.base_uri');
        $this->model = (string) config('ai-assistant.llm.openai.model', config('ai-assistant.openai_model', 'gpt-6-luna'));
        $this->maxTokens = (int) config('ai-assistant.llm.openai.max_tokens', config('ai-assistant.max_tokens', 2000));
        $this->temperature = (float) config('ai-assistant.llm.openai.temperature', 0.7);
        $this->timeout = (float) config('ai-assistant.llm.openai.timeout', 45);
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $budgetService = new TokenBudgetService(calibrationModel: LunaModelPolicy::OPENAI);
        $prepared = $budgetService->prepare($messages, (array) ($options['tools'] ?? []), (string) ($options['budget_profile'] ?? $options['profile'] ?? 'normal'), array_key_exists('budget_limits', $options) ? (array) $options['budget_limits'] : null);
        $messages = $prepared['messages'];
        $model = LunaModelPolicy::assert((string) ($options['model'] ?? $this->model));
        $maxTokens = min(
            max(1, (int) ($options['max_completion_tokens'] ?? $options['max_tokens'] ?? $this->maxTokens)),
            $prepared['max_completion_tokens'],
        );
        $temperature = $options['temperature'] ?? $this->temperature;
        $timeout = $this->positiveFloat($options['timeout'] ?? $this->timeout, $this->timeout);

        $requestPayload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => $temperature,
        ];

        if (!empty($options['tools'])) {
            $requestPayload['tools'] = $options['tools'];
            // Optionally force tool choice if needed
            // $requestPayload['tool_choice'] = 'auto';
        }

        foreach (['tool_choice', 'response_format'] as $optionKey) {
            if (array_key_exists($optionKey, $options)) {
                $requestPayload[$optionKey] = $options[$optionKey];
            }
        }

        $requestPayload['max_completion_tokens'] = $maxTokens;
        $requestPayload['reasoning_effort'] = 'none';
        unset($requestPayload['max_tokens']);

        try {
            $this->logging->technical('ai.openai.request', [
                'model' => $model,
                'messages_count' => count($messages),
                'max_tokens' => $maxTokens,
                'has_tools' => !empty($options['tools']),
            ]);

            $startTime = microtime(true);

            $response = $this->makeClient($timeout)->chat()->create($requestPayload);
            LunaModelPolicy::assert((string) $response->model);

            $duration = microtime(true) - $startTime;

            $message = $response->choices[0]->message;

            $result = [
                'content' => $message->content ?? '',
                'role' => $message->role,
                'tokens_used' => $response->usage->totalTokens,
                'input_tokens' => $response->usage->promptTokens ?? null,
                'output_tokens' => $response->usage->completionTokens ?? null,
                'model' => $response->model,
                'provider' => 'openai',
                'finish_reason' => $response->choices[0]->finishReason,
            ];

            // Если модель решила вызвать инструмент
            if (!empty($message->toolCalls)) {
                $result['tool_calls'] = array_map(function ($toolCall) {
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

            $this->logging->technical('ai.openai.success', [
                'model' => $model,
                'tokens_used' => $result['tokens_used'],
                'duration_ms' => round($duration * 1000, 2),
            ]);

            $result['token_calibration'] = $budgetService->calibration($prepared, (int) ($result['input_tokens'] ?? 0));
            $this->logging->technical('ai.token_calibration', $result['token_calibration']);

            return $result;

        } catch (\Exception $e) {
            if (app()->bound(AssistantRequestExecutionContext::class)) {
                app(AssistantRequestExecutionContext::class)->assertCanContinue();
            }
            $this->logging->technical('ai.openai.error', [
                'model' => $model,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ], 'error');

            throw $e;
        }
    }

    public function countTokens(string $text): int
    {
        return (new TokenCounter())->text($text);
    }

    public function isAvailable(): bool
    {
        return trim($this->apiKey) !== '';
    }

    public function getModel(): string
    {
        return $this->model;
    }

    private function makeClient(float $timeout): object
    {
        $httpOptions = app()->bound(AssistantRequestExecutionContext::class)
            ? AssistantHttpRequestOptions::forContext($timeout, app(AssistantRequestExecutionContext::class))
            : [
                'timeout' => $timeout,
                'connect_timeout' => min(5.0, $timeout),
            ];

        $factory = OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withHttpClient($this->httpClient ?? new GuzzleClient($httpOptions));

        if (is_string($this->baseUri) && trim($this->baseUri) !== '') {
            $factory = $factory->withBaseUri($this->baseUri);
        }

        return $factory->make();
    }

    private function positiveFloat(mixed $value, float $default): float
    {
        if (!is_numeric($value)) {
            return $default;
        }

        $normalized = (float) $value;

        return $normalized > 0 ? $normalized : $default;
    }
}
