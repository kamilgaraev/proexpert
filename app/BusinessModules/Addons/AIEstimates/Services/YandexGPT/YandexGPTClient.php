<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\YandexGPT;

use App\Exceptions\AI\AIAuthenticationException;
use App\Exceptions\AI\AIParsingException;
use App\Exceptions\AI\AIQuotaExceededException;
use App\Exceptions\AI\AIServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexGPTClient
{
    protected string $apiKey;
    protected string $folderId;
    protected string $modelUri;
    protected float $temperature;
    protected int $maxTokens;

    public function __construct()
    {
        $this->apiKey = config('services.yandexgpt.api_key', env('YANDEX_API_KEY'));
        $this->folderId = config('services.yandexgpt.folder_id', env('YANDEX_FOLDER_ID'));
        $this->modelUri = config('services.yandexgpt.model_uri', env('YANDEX_MODEL_URI'));
        $this->temperature = config('ai-estimates.ai.temperature', 0.3);
        $this->maxTokens = config('ai-estimates.ai.max_tokens', 2000);
    }

    public function generateEstimate(string $userPrompt, string $systemPrompt, array $options = []): array
    {
        $startTime = microtime(true);

        try {
            $response = Http::timeout(120)
                ->withHeaders([
                    'Authorization' => 'Api-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                    'x-folder-id' => $this->folderId,
                ])
                ->post('https://llm.api.cloud.yandex.net/foundationModels/v1/completion', [
                    'modelUri' => $this->modelUri,
                    'completionOptions' => [
                        'stream' => false,
                        'temperature' => $options['temperature'] ?? $this->temperature,
                        'maxTokens' => $options['maxTokens'] ?? $this->maxTokens,
                    ],
                    'messages' => [
                        [
                            'role' => 'system',
                            'text' => $systemPrompt,
                        ],
                        [
                            'role' => 'user',
                            'text' => $userPrompt,
                        ],
                    ],
                ]);

            $processingTime = round((microtime(true) - $startTime) * 1000); // в миллисекундах

            // Обработка различных HTTP ошибок
            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->body();
                
                Log::error('[YandexGPTClient] API request failed', [
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                ]);
                
                // 401/403 - проблемы с аутентификацией/авторизацией
                if (in_array($statusCode, [401, 403])) {
                    throw new AIAuthenticationException(
                        'Ошибка доступа к YandexGPT API. Проверьте настройки API ключа.',
                        $statusCode
                    );
                }
                
                // 429 - превышен лимит запросов
                if ($statusCode === 429) {
                    throw new AIQuotaExceededException(
                        'Превышен лимит запросов к YandexGPT. Попробуйте позже.',
                        $statusCode
                    );
                }
                
                // 503 - сервис недоступен
                if ($statusCode === 503) {
                    throw new AIServiceUnavailableException(
                        'YandexGPT временно недоступен. Попробуйте позже.',
                        $statusCode
                    );
                }
                
                // Прочие ошибки API
                throw new AIServiceUnavailableException(
                    'Ошибка при обращении к YandexGPT API: ' . $errorBody,
                    $statusCode
                );
            }

            $result = $response->json();

            // Извлекаем текст ответа
            $responseText = $result['result']['alternatives'][0]['message']['text'] ?? '';
            
            if (empty($responseText)) {
                throw new AIParsingException(
                    'YandexGPT вернул пустой ответ.'
                );
            }
            
            // Парсим JSON из ответа AI
            $estimateData = $this->parseAIResponse($responseText);

            // Получаем количество использованных токенов
            $tokensUsed = $result['result']['usage']['totalTokens'] ?? 0;

            return [
                'estimate_data' => $estimateData,
                'tokens_used' => $tokensUsed,
                'processing_time_ms' => $processingTime,
                'raw_response' => $responseText,
            ];

        } catch (ConnectionException $e) {
            // Ошибки сети / таймауты
            Log::error('[YandexGPTClient] Connection failed', [
                'error' => $e->getMessage(),
            ]);
            
            throw new AIServiceUnavailableException(
                'Не удалось подключиться к YandexGPT. Проверьте соединение с интернетом.',
                0,
                $e
            );
            
        } catch (RequestException $e) {
            // HTTP ошибки
            Log::error('[YandexGPTClient] Request failed', [
                'error' => $e->getMessage(),
            ]);
            
            throw new AIServiceUnavailableException(
                'Ошибка при запросе к YandexGPT.',
                0,
                $e
            );
            
        } catch (AIServiceUnavailableException | AIAuthenticationException | AIQuotaExceededException | AIParsingException $e) {
            // Пробрасываем наши кастомные исключения как есть
            throw $e;
            
        } catch (\Exception $e) {
            // Прочие непредвиденные ошибки
            Log::error('[YandexGPTClient] Unexpected error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new AIServiceUnavailableException(
                'Произошла непредвиденная ошибка при работе с YandexGPT.',
                0,
                $e
            );
        }
    }

    protected function parseAIResponse(string $responseText): array
    {
        // Пытаемся извлечь JSON из ответа
        // AI может вернуть текст с объяснениями, нужно найти JSON блок
        
        // Пробуем напрямую распарсить
        $decoded = json_decode($responseText, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }

        // Пытаемся найти JSON блок в тексте
        if (preg_match('/\{[\s\S]*\}/', $responseText, $matches)) {
            $decoded = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Если не удалось распарсить, выбрасываем исключение
        Log::error('[YandexGPTClient] Failed to parse AI response as JSON', [
            'response' => $responseText,
            'json_error' => json_last_error_msg(),
        ]);

        throw new AIParsingException(
            'Не удалось распарсить ответ от YandexGPT. Попробуйте повторить запрос.'
        );
    }

    public function setTemperature(float $temperature): self
    {
        $this->temperature = $temperature;
        return $this;
    }

    public function setMaxTokens(int $maxTokens): self
    {
        $this->maxTokens = $maxTokens;
        return $this;
    }
}
