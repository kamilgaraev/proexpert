<?php

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Http;

class DeepSeekProvider implements LLMProviderInterface
{
    protected LoggingService $logging;
    protected string $apiKey;
    protected string $model;
    protected int $maxTokens;
    protected float $temperature;
    protected string $endpoint = 'https://api.deepseek.com/v1/chat/completions';

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
        $this->apiKey = config('ai-assistant.llm.deepseek.api_key') ?? '';
        $this->model = config('ai-assistant.llm.deepseek.model', 'deepseek-chat');
        $this->maxTokens = config('ai-assistant.llm.deepseek.max_tokens', 2000);
        $this->temperature = config('ai-assistant.llm.deepseek.temperature', 0.7);
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('DeepSeek API key not configured');
        }

        $model = $options['model'] ?? $this->model;
        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;
        $temperature = $options['temperature'] ?? $this->temperature;

        try {
            $this->logging->technical('ai.deepseek.request', [
                'model' => $model,
                'messages_count' => count($messages),
                'max_tokens' => $maxTokens,
            ]);

            $startTime = microtime(true);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($this->endpoint, [
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $maxTokens,
                'temperature' => $temperature,
                'stream' => false,
            ]);

            $duration = microtime(true) - $startTime;

            if ($response->failed()) {
                $errorBody = $response->body();
                $this->logging->technical('ai.deepseek.error', [
                    'model' => $model,
                    'status' => $response->status(),
                    'error_body' => $errorBody,
                ], 'error');

                throw new \RuntimeException(
                    'DeepSeek API error: ' . $errorBody
                );
            }

            $data = $response->json();

            // Парсим ответ DeepSeek (OpenAI-совместимый формат)
            $result = $this->parseResponse($data, $model);

            $this->logging->technical('ai.deepseek.success', [
                'model' => $model,
                'tokens_used' => $result['tokens_used'],
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logging->technical('ai.deepseek.error', [
                'model' => $model,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ], 'error');

            throw $e;
        }
    }

    /**
     * Парсит ответ от DeepSeek API (OpenAI-совместимый формат)
     */
    protected function parseResponse(array $data, string $model): array
    {
        if (!isset($data['choices'][0])) {
            throw new \RuntimeException('Invalid DeepSeek response format: no choices');
        }

        $choice = $data['choices'][0];
        $message = $choice['message'] ?? [];

        if (!isset($message['content'])) {
            throw new \RuntimeException('Invalid DeepSeek response format: no content');
        }

        $usage = $data['usage'] ?? [];

        // Извлекаем информацию о cache для правильного расчета стоимости
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        
        // DeepSeek может возвращать информацию о cache hit/miss
        $promptCacheHitTokens = $usage['prompt_cache_hit_tokens'] ?? 0;
        $promptCacheMissTokens = $usage['prompt_cache_miss_tokens'] ?? 0;
        
        // Если нет детальной информации о cache, считаем что все cache miss
        // (можно будет улучшить, когда узнаем точный формат ответа)
        if ($promptCacheHitTokens == 0 && $promptCacheMissTokens == 0) {
            $promptCacheMissTokens = $promptTokens;
        }

        return [
            'content' => $message['content'],
            'role' => $message['role'] ?? 'assistant',
            'tokens_used' => $promptTokens + $completionTokens,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'prompt_cache_hit_tokens' => $promptCacheHitTokens,
            'prompt_cache_miss_tokens' => $promptCacheMissTokens,
            'model' => $data['model'] ?? $model,
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
        ];
    }

    public function countTokens(string $text): int
    {
        // Приблизительная оценка для русского текста
        // DeepSeek использует похожий токенизатор как OpenAI: ~1 токен = 4 символа
        return (int) (mb_strlen($text, 'UTF-8') / 4);
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getModel(): string
    {
        return $this->model;
    }
}

