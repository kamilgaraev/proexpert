<?php

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use OpenAI;
use App\Services\Logging\LoggingService;
use Illuminate\Support\Facades\Log;

class OpenAIProvider implements LLMProviderInterface
{
    protected $client;
    protected LoggingService $logging;
    protected string $model;
    protected int $maxTokens;

    public function __construct(LoggingService $logging)
    {
        $this->logging = $logging;
        $this->model = config('ai-assistant.openai_model', 'gpt-4o-mini');
        $this->maxTokens = config('ai-assistant.max_tokens', 2000);
        
        $apiKey = config('ai-assistant.openai_api_key');
        
        if ($apiKey) {
            $this->client = OpenAI::client($apiKey);
        }
    }

    public function chat(array $messages, array $options = []): array
    {
        if (!$this->isAvailable()) {
            throw new \RuntimeException('OpenAI API key not configured');
        }

        $model = $options['model'] ?? $this->model;
        $maxTokens = $options['max_tokens'] ?? $this->maxTokens;
        $temperature = $options['temperature'] ?? 0.7;
        
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

        try {
            $this->logging->technical('ai.openai.request', [
                'model' => $model,
                'messages_count' => count($messages),
                'max_tokens' => $maxTokens,
                'has_tools' => !empty($options['tools']),
            ]);

            $startTime = microtime(true);

            $response = $this->client->chat()->create($requestPayload);

            $duration = microtime(true) - $startTime;
            
            $message = $response->choices[0]->message;

            $result = [
                'content' => $message->content ?? '',
                'role' => $message->role,
                'tokens_used' => $response->usage->totalTokens,
                'input_tokens' => $response->usage->promptTokens ?? null,
                'output_tokens' => $response->usage->completionTokens ?? null,
                'model' => $response->model,
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

            return $result;

        } catch (\Exception $e) {
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
        return (int) (strlen($text) / 4);
    }

    public function isAvailable(): bool
    {
        return $this->client !== null && config('ai-assistant.openai_api_key') !== null;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}

