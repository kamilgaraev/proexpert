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
        $prepared = $budgetService->resolvePrepared($options['_prepared_token_budget'] ?? null, $messages, (array) ($options['tools'] ?? []), (string) ($options['budget_profile'] ?? $options['profile'] ?? 'normal'), array_key_exists('budget_limits', $options) ? (array) $options['budget_limits'] : null);
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

    public function responses(array $input, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('ai_provider_unavailable');
        }
        if (app()->bound(AssistantRequestExecutionContext::class)) {
            app(AssistantRequestExecutionContext::class)->assertCanContinue();
        }
        $model = LunaModelPolicy::assert((string) ($options['model'] ?? $this->model));
        $budgetService = new TokenBudgetService(calibrationModel: LunaModelPolicy::OPENAI);
        $tools = (array) ($options['tools'] ?? []);
        $prepared = $budgetService->resolvePrepared($options['_prepared_token_budget'] ?? null, $input, $tools,
            (string) ($options['budget_profile'] ?? 'normal'), isset($options['budget_limits']) ? (array) $options['budget_limits'] : null);
        $maxTokens = min(max(1, (int) ($options['max_completion_tokens'] ?? $options['max_tokens'] ?? $this->maxTokens)), $prepared['max_completion_tokens']);
        $payload = OpenAIProvider::nativePayload($model, $prepared['messages'], $tools, $maxTokens);
        $timeout = $this->positiveFloat($options['timeout'] ?? $this->timeout, $this->timeout);
        try {
            $response = $this->makeClient($timeout)->responses()->create($payload);
            if (app()->bound(AssistantRequestExecutionContext::class)) {
                app(AssistantRequestExecutionContext::class)->assertCanContinue();
            }
            $result = OpenAIProvider::nativeResult($response->toArray(), $model, 'openai', $tools);
            $result['token_calibration'] = ($result['provider_usage_available'] ?? false)
                ? $budgetService->calibration($prepared, (int) $result['input_tokens'])
                : ['actual_input_tokens' => null, 'provider_usage_available' => false, 'persisted' => false, 'profile_input_exceeded' => false];
            return $result;
        } catch (\Throwable $exception) {
            if (app()->bound(AssistantRequestExecutionContext::class)) {
                app(AssistantRequestExecutionContext::class)->assertCanContinue();
            }
            $this->logging->technical('ai.openai.native_failed', ['exception_class' => $exception::class], 'warning');
            throw $exception;
        }
    }

    public static function nativePayload(string $model, array $input, array $tools, int $maxTokens): array
    {
        $names = [];
        foreach ($tools as $tool) {
            $name = $tool['name'] ?? null;
            if (($tool['type'] ?? null) !== 'function' || isset($tool['function']) || !is_string($name)
                || !preg_match('/^[a-zA-Z_][a-zA-Z0-9_]{0,63}$/D', $name) || isset($names[$name])
                || !is_array($tool['parameters'] ?? null)) {
                throw new \DomainException('assistant_native_tools_invalid');
            }
            $names[$name] = true;
        }
        foreach ($input as $item) {
            if (!is_array($item) || isset($item['function']) || isset($item['tool_calls'])
                || !in_array($item['type'] ?? 'message', ['message', 'function_call', 'function_call_output', 'reasoning'], true)
                || (($item['type'] ?? 'message') === 'message' && !in_array($item['role'] ?? null, ['system', 'developer', 'user', 'assistant'], true))) {
                throw new \DomainException('assistant_native_input_invalid');
            }
        }
        TokenBudgetService::assertNativePairs($input);
        return [
            'model' => $model, 'input' => array_values($input), 'tools' => array_values($tools),
            'max_output_tokens' => $maxTokens, 'store' => false, 'stream' => false,
            'parallel_tool_calls' => false, 'reasoning' => ['effort' => 'none'],
            'include' => ['reasoning.encrypted_content'],
        ];
    }

    public static function nativeResult(array $response, string $expectedModel, string $provider, array $tools): array
    {
        $usage = $response['usage'] ?? null;
        $result = [
            'content' => '', 'role' => 'assistant', 'output' => [], 'function_calls' => [],
            'tokens_used' => is_array($usage) ? (int) ($usage['total_tokens'] ?? 0) : 0,
            'input_tokens' => is_array($usage) ? ($usage['input_tokens'] ?? null) : null,
            'output_tokens' => is_array($usage) ? ($usage['output_tokens'] ?? null) : null,
            'provider_usage_available' => is_array($usage), 'provider' => $provider,
            'response_status' => $response['status'] ?? null,
            'incomplete_reason' => $response['incomplete_details']['reason'] ?? null,
        ];
        if (($response['status'] ?? null) === 'incomplete') {
            throw new \App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete($result);
        }
        $model = $response['model'] ?? null;
        $ref = $response['id'] ?? null;
        if (($response['status'] ?? null) !== 'completed' || ($response['error'] ?? null) !== null
            || !is_string($model) || $model !== $expectedModel || !is_string($ref)
            || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $ref)
            || !is_array($response['output'] ?? null) || !array_is_list($response['output'])
            || count($response['output']) < 1 || count($response['output']) > 64) {
            $safeModel = is_string($model) && preg_match('/^[a-zA-Z0-9_.\/-]{1,100}$/D', $model) ? $model : 'invalid';
            $safeStatus = is_string($response['status'] ?? null) && preg_match('/^[a-z_]{1,24}$/D', $response['status']) ? $response['status'] : 'invalid';
            $validReference = is_string($ref) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $ref);
            $validOutput = is_array($response['output'] ?? null) && array_is_list($response['output']);
            throw new \DomainException('assistant_native_response_invalid:model='.$safeModel.';status='.$safeStatus.';reference='.(int) $validReference.';output='.(int) $validOutput);
        }
        $allowed = array_fill_keys(array_column($tools, 'name'), true);
        $ids = [];
        $calls = [];
        foreach ($response['output'] as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $item['id']) || isset($ids[$item['id']])) {
                throw new \DomainException('assistant_native_item_invalid');
            }
            $ids[$item['id']] = true;
            if ($calls !== [] && ($item['type'] ?? null) !== 'function_call') {
                throw new \DomainException('assistant_native_item_order_invalid');
            }
            if (($item['type'] ?? null) === 'function_call') {
                $callId = $item['call_id'] ?? null;
                $arguments = $item['arguments'] ?? null;
                if (($item['status'] ?? null) !== 'completed' || !is_string($callId)
                    || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $callId) || isset($calls[$callId])
                    || !is_string($item['name'] ?? null) || !isset($allowed[$item['name']])
                    || !is_string($arguments) || strlen($arguments) > 65536
                    || !is_object(json_decode($arguments)) || json_last_error() !== JSON_ERROR_NONE) {
                    throw new \DomainException('assistant_native_call_invalid');
                }
                $calls[$callId] = true;
                $result['function_calls'][] = $item;
            } elseif (($item['type'] ?? null) === 'message') {
                if (($item['role'] ?? null) !== 'assistant' || ($item['status'] ?? null) !== 'completed'
                    || !is_array($item['content'] ?? null)) {
                    throw new \DomainException('assistant_native_message_invalid');
                }
                foreach ($item['content'] as $part) {
                    if (($part['type'] ?? null) === 'output_text' && is_string($part['text'] ?? null)) {
                        $result['content'] .= $part['text'];
                    } elseif (($part['type'] ?? null) === 'refusal' && is_string($part['refusal'] ?? null)) {
                        $result['content'] .= $part['refusal'];
                    } else {
                        throw new \DomainException('assistant_native_content_invalid');
                    }
                }
            } elseif (($item['type'] ?? null) === 'reasoning') {
                if (($item['summary'] ?? null) !== [] || (isset($item['encrypted_content'])
                    && (!is_string($item['encrypted_content']) || strlen($item['encrypted_content']) > 65536))) {
                    throw new \DomainException('assistant_native_reasoning_invalid');
                }
            } else {
                throw new \DomainException('assistant_native_item_invalid');
            }
            $result['output'][] = $item;
        }
        if (count($result['function_calls']) > 1 || ($result['function_calls'] === [] && trim($result['content']) === '')) {
            throw new \DomainException('assistant_native_output_invalid');
        }
        $result['model'] = $model;
        $result['actual_model'] = $model;
        $result['provider_response_ref'] = $ref;
        $result['api_method'] = 'responses';
        $result['model_invoked'] = true;
        $result['finish_reason'] = $result['function_calls'] === [] ? 'stop' : 'function_call';
        return $result;
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
