<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use App\Support\AI\LunaModelPolicy;
use App\Support\AI\TokenCounter;
use App\Support\AI\TokenBudgetService;
use App\Services\Logging\LoggingService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
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

    public function __construct(
        private readonly LoggingService $logging,
        private readonly ?ClientInterface $httpClient = null
    ) {
        $this->apiKey = (string) config('ai-assistant.llm.timeweb.api_key', '');
        $this->baseUri = (string) config('ai-assistant.llm.timeweb.base_uri', 'https://api.timeweb.ai/v1');
        $this->model = (string) config('ai-assistant.llm.timeweb.model', 'openai/gpt-6-luna');
        $this->maxTokens = (int) config('ai-assistant.llm.timeweb.max_tokens', 2000);
        $this->temperature = (float) config('ai-assistant.llm.timeweb.temperature', 0.7);
        $this->timeout = (float) config('ai-assistant.llm.timeweb.timeout', 25);
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new RuntimeException('Timeweb AI Gateway API key not configured');
        }

        $budgetService = new TokenBudgetService();
        $prepared = $budgetService->prepare($messages, (array) ($options['tools'] ?? []), (string) ($options['budget_profile'] ?? $options['profile'] ?? 'normal'), array_key_exists('budget_limits', $options) ? (array) $options['budget_limits'] : null);
        $messages = $prepared['messages'];
        $profile = $this->profile($options);
        $profileConfig = $this->profileConfig($profile);
        $models = $this->models($options, $profileConfig);
        $maxTokens = min(
            max(1, (int) ($options['max_completion_tokens'] ?? $options['max_tokens'] ?? $profileConfig['max_tokens'] ?? $this->maxTokens)),
            $prepared['max_completion_tokens'],
        );
        $timeout = $this->positiveFloat($options['timeout'] ?? $profileConfig['timeout'] ?? $this->timeout, $this->timeout);
        $lastException = null;

        foreach ($models as $attempt => $model) {
            $adapter = new TimewebResponsesAdapter();
            $requestPayload = $adapter->payload($model, $messages, $maxTokens, $options);

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
                $response = $this->makeClient($timeout)->responses()->create($requestPayload);
                $duration = microtime(true) - $startTime;

                $result = $adapter->result($response, $model);
                $result['profile'] = $profile;
                $result['route_attempt'] = $attempt + 1;
                $result['route_fallback'] = $attempt > 0;

                $result['token_calibration'] = ($result['provider_usage_available'] ?? false) === true
                    ? $budgetService->calibration($prepared, (int) $result['input_tokens'])
                    : ['actual_input_tokens' => null, 'provider_usage_available' => false, 'persisted' => false, 'profile_input_exceeded' => false];
                $this->logging->technical('ai.token_calibration', $result['token_calibration']);

                if (($result['response_status'] ?? null) === 'incomplete') {
                    $exception = new AssistantResponseIncomplete($result);
                    $this->logging->technical('ai.timeweb.incomplete', [
                        'reason' => $exception->reason,
                        'usage' => $exception->providerUsage,
                    ], 'warning');
                    throw $exception;
                }

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
                    'incomplete_reason' => $exception instanceof AssistantResponseIncomplete ? $exception->reason : null,
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
        return (new TokenCounter())->text($text);
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
            ->withHttpClient($this->httpClient ?? new GuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => $connectTimeout,
            ]))
            ->make();
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
            return [LunaModelPolicy::assert((string) $options['model'], 'timeweb')];
        }

        $models = $profileConfig['models'] ?? [$this->model];

        if (is_string($models)) {
            $models = explode(',', $models);
        }

        if (!is_array($models)) {
            $models = [$this->model];
        }

        foreach ((array) $models as $model) {
            if (LunaModelPolicy::isLuna((string) $model, 'timeweb')) {
                return [LunaModelPolicy::TIMEWEB];
            }
        }

        return [LunaModelPolicy::TIMEWEB];
    }
}
