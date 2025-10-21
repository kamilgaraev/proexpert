<?php

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexGPTProvider implements LLMProviderInterface
{
    protected LoggingService $logging;
    protected string $apiKey;
    protected string $folderId;
    protected string $modelUri;
    protected int $maxTokens;
    protected float $temperature;
    protected string $endpoint = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
        $this->apiKey = config('ai-assistant.llm.yandex.api_key') ?? '';
        $this->folderId = config('ai-assistant.llm.yandex.folder_id') ?? '';
        $this->modelUri = config('ai-assistant.llm.yandex.model_uri') ?? '';
        $this->maxTokens = config('ai-assistant.llm.yandex.max_tokens') ?? 2000;
        $this->temperature = config('ai-assistant.llm.yandex.temperature') ?? 0.7;
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('YandexGPT API key or Folder ID not configured');
        }

        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;
        $temperature = $options['temperature'] ?? $this->temperature;
        $modelUri = $options['model_uri'] ?? $this->modelUri;

        // Конвертируем формат сообщений из OpenAI в YandexGPT
        $yandexMessages = $this->convertMessages($messages);

        try {
            $this->logging->technical('ai.yandex.request', [
                'model_uri' => $modelUri,
                'messages_count' => count($yandexMessages),
                'max_tokens' => $maxTokens,
            ]);

            $startTime = microtime(true);

            $response = Http::withHeaders([
                'Authorization' => 'Api-Key ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'x-folder-id' => $this->folderId,
            ])->timeout(60)->post($this->endpoint, [
                'modelUri' => $modelUri,
                'completionOptions' => [
                    'stream' => false,
                    'temperature' => $temperature,
                    'maxTokens' => (string)$maxTokens,
                ],
                'messages' => $yandexMessages,
            ]);

            $duration = microtime(true) - $startTime;

            if ($response->failed()) {
                throw new \RuntimeException(
                    'YandexGPT API error: ' . $response->body()
                );
            }

            $data = $response->json();

            // Парсим ответ YandexGPT
            $result = $this->parseResponse($data);

            $this->logging->technical('ai.yandex.success', [
                'model_uri' => $modelUri,
                'tokens_used' => $result['tokens_used'],
                'duration_ms' => round($duration * 1000, 2),
            ]);

            return $result;

        } catch (\Exception $e) {
            $this->logging->technical('ai.yandex.error', [
                'model_uri' => $modelUri,
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ], 'error');

            throw $e;
        }
    }

    /**
     * Конвертирует сообщения из формата OpenAI в формат YandexGPT
     */
    protected function convertMessages(array $messages): array
    {
        $converted = [];
        
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';
            
            // YandexGPT использует 'text' вместо 'content'
            $converted[] = [
                'role' => $role === 'assistant' ? 'assistant' : ($role === 'system' ? 'system' : 'user'),
                'text' => $content,
            ];
        }
        
        return $converted;
    }

    /**
     * Парсит ответ от YandexGPT
     */
    protected function parseResponse(array $data): array
    {
        $alternative = $data['result']['alternatives'][0] ?? null;
        
        if (!$alternative) {
            throw new \RuntimeException('Invalid YandexGPT response format');
        }

        $message = $alternative['message'] ?? [];
        $usage = $data['result']['usage'] ?? [];

        return [
            'content' => $message['text'] ?? '',
            'role' => $message['role'] ?? 'assistant',
            'tokens_used' => ($usage['inputTextTokens'] ?? 0) + ($usage['completionTokens'] ?? 0),
            'model' => $data['result']['modelVersion'] ?? 'yandexgpt',
            'finish_reason' => $alternative['status'] ?? 'ALTERNATIVE_STATUS_FINAL',
        ];
    }

    public function countTokens(string $text): int
    {
        // Приблизительная оценка для русского текста
        // YandexGPT: ~1 токен = 3-4 символа для русского языка
        return (int) (mb_strlen($text, 'UTF-8') / 3.5);
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && !empty($this->folderId) && !empty($this->modelUri);
    }

    public function getModel(): string
    {
        return $this->modelUri;
    }
}

