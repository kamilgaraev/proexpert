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

            $requestPayload = [
                'modelUri' => $modelUri,
                'completionOptions' => [
                    'stream' => false,
                    'temperature' => $temperature,
                    'maxTokens' => (string)$maxTokens,
                ],
                'messages' => $yandexMessages,
            ];
            
            if (!empty($options['tools'])) {
                // YandexGPT expects tools in a specific format, we need to convert from standard OpenAI format
                $requestPayload['tools'] = $this->convertTools($options['tools']);
            }

            $response = Http::withHeaders([
                'Authorization' => 'Api-Key ' . $this->apiKey,
                'Content-Type' => 'application/json',
                'x-folder-id' => $this->folderId,
            ])->timeout(60)->post($this->endpoint, $requestPayload);

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

    protected function convertMessages(array $messages): array
    {
        $converted = [];
        
        foreach ($messages as $message) {
            $role = $message['role'] ?? 'user';
            $content = $message['content'] ?? '';
            
            // Если это сообщение с вызовом функции
            $toolCalls = $message['tool_calls'] ?? null;
            
            $yandexMessage = [];
            
            if ($role === 'assistant') {
                $yandexMessage['role'] = 'assistant';
                
                // В YandexGPT если есть toolCalls, текст может быть обязательным,
                // поэтому добавим заглушку если он пустой
                if (!empty($toolCalls)) {
                    $yandexMessage['text'] = !empty($content) ? $content : 'Вызываю инструмент...';
                    
                    // Конвертируем tool_calls
                    $yandexToolCalls = [];
                    foreach ($toolCalls as $call) {
                        $args = is_string($call['function']['arguments']) 
                            ? json_decode($call['function']['arguments'], true) 
                            : ($call['function']['arguments'] ?? []);
                            
                        // Если аргументы пустые, кастим в объект stdClass
                        if (empty($args)) {
                            $args = new \stdClass();
                        }

                        $yandexToolCalls[] = [
                            'functionCall' => [
                                'name' => $call['function']['name'] ?? '',
                                'arguments' => $args,
                            ]
                        ];
                    }
                    $yandexMessage['toolCallList'] = ['toolCalls' => $yandexToolCalls];
                } else {
                    $yandexMessage['text'] = !empty($content) ? $content : '...';
                }
            } elseif ($role === 'tool') {
                // Результат работы инструмента в YandexGPT передается через toolResultList
                $yandexMessage['role'] = 'user'; // По умолчанию отправим как от пользователя с результатами (или assistant, если требует API)
                $yandexMessage['text'] = '';
                
                $yandexMessage['toolResultList'] = [
                    'toolResults' => [
                        [
                            'functionResult' => [
                                'name' => $message['name'] ?? '',
                                'content' => is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE),
                            ]
                        ]
                    ]
                ];
            } else {
                // user или system
                $yandexMessage['role'] = $role === 'system' ? 'system' : 'user';
                $yandexMessage['text'] = !empty($content) ? $content : '...';
            }
            
            $converted[] = $yandexMessage;
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

        $inputTokens = (int) ($usage['inputTextTokens'] ?? 0);
        $outputTokens = (int) ($usage['completionTokens'] ?? 0);
        $totalTokens = $inputTokens + $outputTokens;

        $result = [
            'content' => $message['text'] ?? '',
            'role' => $message['role'] ?? 'assistant',
            'tokens_used' => $totalTokens,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'model' => $data['result']['modelVersion'] ?? $this->modelUri,
            'finish_reason' => $alternative['status'] ?? 'ALTERNATIVE_STATUS_FINAL',
        ];

        // Обрабатываем вызовы инструментов YandexGPT
        if (isset($alternative['status']) && $alternative['status'] === 'ALTERNATIVE_STATUS_TOOL_CALLS') {
            
            $toolCallsList = $message['toolCallList']['toolCalls'] ?? [];
            
            if (!empty($toolCallsList)) {
                $result['tool_calls'] = [];
                
                foreach ($toolCallsList as $toolCallData) {
                    if (!empty($toolCallData['functionCall'])) {
                        $functionName = $toolCallData['functionCall']['name'] ?? '';
                        $arguments = $toolCallData['functionCall']['arguments'] ?? [];
                        
                        $result['tool_calls'][] = [
                            'id' => uniqid('call_'),
                            'type' => 'function',
                            'function' => [
                                'name' => $functionName,
                                'arguments' => is_string($arguments) ? $arguments : json_encode($arguments, JSON_UNESCAPED_UNICODE),
                            ]
                        ];
                    }
                }
            }
        }

        return $result;
    }
    
    protected function convertTools(array $tools): array
    {
        $yandexTools = [];
        
        foreach ($tools as $tool) {
            if (isset($tool['type']) && $tool['type'] === 'function') {
                $parameters = $this->normalizeSchemaForYandex($tool['function']['parameters'] ?? []);
                
                // Важно: YandexGPT ожидает JSON объект (map) для параметров
                if (empty($parameters)) {
                    $parameters = new \stdClass();
                } else {
                    if (isset($parameters['properties']) && empty($parameters['properties'])) {
                        $parameters['properties'] = new \stdClass();
                    }
                    // 'required' in JSON Schema is typically an array of strings.
                    // If it's an empty array, it SHOULD be an array [], but YandexGPT might be complaining 
                    // about other empty maps being arrays. Let's make sure 'properties' is an object.
                    // Wait, the error is "cannot unmarshal array into Go value of type map[string]json.RawMessage".
                    // This means YandexGPT expects a map (object) but got an array.
                    // This can happen if $parameters is `[]`, or $parameters['properties'] is `[]`.
                }
                
                $yandexTools[] = [
                    'function' => [
                        'name' => $tool['function']['name'],
                        'description' => $tool['function']['description'] ?? '',
                        'parameters' => $parameters,
                    ]
                ];
            }
        }
        
        return $yandexTools;
    }

    protected function normalizeSchemaForYandex(array $schema): array|\stdClass
    {
        if ($schema === []) {
            return new \stdClass();
        }

        $normalized = [];

        foreach ($schema as $key => $value) {
            if ($key === 'properties' || $key === 'definitions' || $key === '$defs') {
                $normalized[$key] = $this->normalizeSchemaMap($value);
                continue;
            }

            if ($key === 'items' && is_array($value)) {
                $normalized[$key] = $this->normalizeSchemaForYandex($value);
                continue;
            }

            if (is_array($value) && $this->isAssociativeArray($value)) {
                $normalized[$key] = $this->normalizeSchemaForYandex($value);
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    protected function normalizeSchemaMap(mixed $value): array|\stdClass
    {
        if (!is_array($value) || $value === []) {
            return new \stdClass();
        }

        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[(string) $key] = is_array($item)
                ? $this->normalizeSchemaForYandex($item)
                : $item;
        }

        return $normalized;
    }

    protected function isAssociativeArray(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
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
