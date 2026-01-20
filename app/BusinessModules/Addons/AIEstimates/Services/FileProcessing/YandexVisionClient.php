<?php

namespace App\BusinessModules\Addons\AIEstimates\Services\FileProcessing;

use App\Exceptions\AI\AIAuthenticationException;
use App\Exceptions\AI\AIParsingException;
use App\Exceptions\AI\AIQuotaExceededException;
use App\Exceptions\AI\AIServiceUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YandexVisionClient
{
    protected string $apiKey;
    protected string $folderId;

    public function __construct()
    {
        $this->apiKey = config('services.yandex_vision.api_key', env('YANDEX_VISION_API_KEY'));
        $this->folderId = config('services.yandex_vision.folder_id', env('YANDEX_VISION_FOLDER_ID'));
    }

    public function extractText(UploadedFile $file): string
    {
        try {
            $content = base64_encode($file->get());

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Api-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze', [
                    'folderId' => $this->folderId,
                    'analyze_specs' => [
                        [
                            'content' => $content,
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'text_detection_config' => [
                                        'language_codes' => ['ru', 'en']
                                    ]
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->body();
                
                Log::error('[YandexVisionClient] API request failed', [
                    'file' => $file->getClientOriginalName(),
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                ]);
                
                // 401/403 - проблемы с аутентификацией/авторизацией
                if (in_array($statusCode, [401, 403])) {
                    throw new AIAuthenticationException(
                        'Ошибка доступа к Yandex Vision API. Проверьте настройки API ключа.',
                        $statusCode
                    );
                }
                
                // 429 - превышен лимит запросов
                if ($statusCode === 429) {
                    throw new AIQuotaExceededException(
                        'Превышен лимит запросов к Yandex Vision. Попробуйте позже.',
                        $statusCode
                    );
                }
                
                // 503 - сервис недоступен
                if ($statusCode === 503) {
                    throw new AIServiceUnavailableException(
                        'Yandex Vision временно недоступен. Попробуйте позже.',
                        $statusCode
                    );
                }
                
                // Прочие ошибки API
                throw new AIServiceUnavailableException(
                    'Ошибка при обращении к Yandex Vision API: ' . $errorBody,
                    $statusCode
                );
            }

            $result = $response->json();

            // Извлекаем текст из ответа
            $texts = [];
            $pages = $result['results'][0]['results'][0]['textDetection']['pages'] ?? [];
            
            foreach ($pages as $page) {
                foreach ($page['blocks'] ?? [] as $block) {
                    foreach ($block['lines'] ?? [] as $line) {
                        if (isset($line['text'])) {
                            $texts[] = $line['text'];
                        }
                    }
                }
            }

            return implode("\n", $texts);

        } catch (ConnectionException $e) {
            Log::error('[YandexVisionClient] Connection failed', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            throw new AIServiceUnavailableException(
                'Не удалось подключиться к Yandex Vision. Проверьте соединение с интернетом.',
                0,
                $e
            );
            
        } catch (RequestException $e) {
            Log::error('[YandexVisionClient] Request failed', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            throw new AIServiceUnavailableException(
                'Ошибка при запросе к Yandex Vision.',
                0,
                $e
            );
            
        } catch (AIServiceUnavailableException | AIAuthenticationException | AIQuotaExceededException | AIParsingException $e) {
            // Пробрасываем наши кастомные исключения как есть
            throw $e;
            
        } catch (\Exception $e) {
            Log::error('[YandexVisionClient] Unexpected error', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            throw new AIServiceUnavailableException(
                'Произошла непредвиденная ошибка при работе с Yandex Vision.',
                0,
                $e
            );
        }
    }

    public function extractStructuredText(UploadedFile $file): array
    {
        try {
            $content = base64_encode($file->get());

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Api-Key ' . $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://vision.api.cloud.yandex.net/vision/v1/batchAnalyze', [
                    'folderId' => $this->folderId,
                    'analyze_specs' => [
                        [
                            'content' => $content,
                            'features' => [
                                [
                                    'type' => 'TEXT_DETECTION',
                                    'text_detection_config' => [
                                        'language_codes' => ['ru', 'en']
                                    ]
                                ],
                            ],
                        ],
                    ],
                ]);

            if (!$response->successful()) {
                $statusCode = $response->status();
                $errorBody = $response->body();
                
                Log::error('[YandexVisionClient] Structured OCR API request failed', [
                    'file' => $file->getClientOriginalName(),
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                ]);
                
                // 401/403 - проблемы с аутентификацией/авторизацией
                if (in_array($statusCode, [401, 403])) {
                    throw new AIAuthenticationException(
                        'Ошибка доступа к Yandex Vision API. Проверьте настройки API ключа.',
                        $statusCode
                    );
                }
                
                // 429 - превышен лимит запросов
                if ($statusCode === 429) {
                    throw new AIQuotaExceededException(
                        'Превышен лимит запросов к Yandex Vision. Попробуйте позже.',
                        $statusCode
                    );
                }
                
                // 503 - сервис недоступен
                if ($statusCode === 503) {
                    throw new AIServiceUnavailableException(
                        'Yandex Vision временно недоступен. Попробуйте позже.',
                        $statusCode
                    );
                }
                
                // Прочие ошибки API
                throw new AIServiceUnavailableException(
                    'Ошибка при обращении к Yandex Vision API: ' . $errorBody,
                    $statusCode
                );
            }

            $result = $response->json();

            return $result['results'][0]['results'][0]['textDetection'] ?? [];

        } catch (ConnectionException $e) {
            Log::error('[YandexVisionClient] Structured OCR connection failed', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            throw new AIServiceUnavailableException(
                'Не удалось подключиться к Yandex Vision. Проверьте соединение с интернетом.',
                0,
                $e
            );
            
        } catch (RequestException $e) {
            Log::error('[YandexVisionClient] Structured OCR request failed', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);
            
            throw new AIServiceUnavailableException(
                'Ошибка при запросе к Yandex Vision.',
                0,
                $e
            );
            
        } catch (AIServiceUnavailableException | AIAuthenticationException | AIQuotaExceededException | AIParsingException $e) {
            // Пробрасываем наши кастомные исключения как есть
            throw $e;
            
        } catch (\Exception $e) {
            Log::error('[YandexVisionClient] Structured OCR unexpected error', [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            throw new AIServiceUnavailableException(
                'Произошла непредвиденная ошибка при работе с Yandex Vision.',
                0,
                $e
            );
        }
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey) && !empty($this->folderId);
    }
}
