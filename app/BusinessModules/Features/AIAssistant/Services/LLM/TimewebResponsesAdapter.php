<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\LLM;

use App\Support\AI\LunaModelPolicy;
use OpenAI\Responses\Responses\CreateResponse;
use RuntimeException;

final class TimewebResponsesAdapter
{
    public function payload(string $model, array $messages, int $maxTokens, array $options): array
    {
        $payload = [
            'model' => LunaModelPolicy::assert($model, 'timeweb'),
            'input' => $this->input($messages),
            'max_output_tokens' => $maxTokens,
            'reasoning' => ['effort' => 'none'],
            'store' => false,
        ];

        if (array_key_exists('tools', $options)) {
            $payload['tools'] = array_map(static function (array $tool): array {
                if (($tool['type'] ?? '') !== 'function') {
                    throw new RuntimeException('Unsupported Timeweb Responses tool type');
                }

                return ['type' => 'function', ...$tool['function'], 'strict' => $tool['function']['strict'] ?? false];
            }, $options['tools']);
        }

        if (array_key_exists('tool_choice', $options)) {
            $choice = $options['tool_choice'];
            $payload['tool_choice'] = is_array($choice)
                ? ['type' => 'function', 'name' => $choice['function']['name']]
                : $choice;
        }

        if (array_key_exists('parallel_tool_calls', $options)) {
            $payload['parallel_tool_calls'] = (bool) $options['parallel_tool_calls'];
        }

        if (array_key_exists('response_format', $options)) {
            $format = $options['response_format'];
            $payload['text']['format'] = ($format['type'] ?? '') === 'json_schema'
                ? ['type' => 'json_schema', ...$format['json_schema']]
                : $format;
        }

        return $payload;
    }

    public function result(CreateResponse $response, string $requestedModel): array
    {
        $model = LunaModelPolicy::assert($response->model ?: $requestedModel, 'timeweb');
        if (!in_array($response->status, ['completed', 'incomplete'], true)) {
            throw new RuntimeException('Timeweb Responses request did not complete');
        }

        $result = [
            'content' => $response->status === 'incomplete' ? '' : (string) $response->outputText,
            'role' => 'assistant',
            'tokens_used' => $response->usage?->totalTokens ?? 0,
            'input_tokens' => $response->usage?->inputTokens,
            'output_tokens' => $response->usage?->outputTokens,
            'provider_usage_available' => $response->usage !== null,
            'usage_source' => $response->usage === null ? 'unavailable' : 'provider_response',
            'model' => $model,
            'provider' => 'timeweb',
            'finish_reason' => $response->status === 'incomplete' ? 'length' : 'stop',
        ];

        if ($response->status === 'incomplete') {
            $result['response_status'] = 'incomplete';
            $result['incomplete_reason'] = $response->incompleteDetails?->reason ?? 'unknown';
        }

        foreach ($response->output as $item) {
            if ($response->status === 'completed' && $item->type === 'function_call') {
                $result['tool_calls'][] = [
                    'id' => $item->callId,
                    'type' => 'function',
                    'function' => ['name' => $item->name, 'arguments' => $item->arguments],
                ];
            }
        }

        if (!empty($result['tool_calls'])) {
            $result['finish_reason'] = 'tool_calls';
        }

        return $result;
    }

    private function input(array $messages): array
    {
        $input = [];
        foreach ($messages as $message) {
            if ($message['role'] === 'tool') {
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $message['tool_call_id'],
                    'output' => $message['content'],
                ];
                continue;
            }

            $content = $message['content'] ?? '';
            if (is_array($content)) {
                $content = array_map(static function (array $part): array {
                    return match ($part['type']) {
                        'text' => ['type' => 'input_text', 'text' => $part['text']],
                        'image_url' => ['type' => 'input_image', 'image_url' => $part['image_url']['url'], 'detail' => $part['image_url']['detail'] ?? 'auto'],
                        default => throw new RuntimeException('Unsupported Timeweb Responses content type'),
                    };
                }, $content);
            }

            if ($content !== '' || empty($message['tool_calls'])) {
                $input[] = ['role' => $message['role'], 'content' => $content];
            }

            foreach ($message['tool_calls'] ?? [] as $call) {
                $input[] = [
                    'type' => 'function_call',
                    'call_id' => $call['id'],
                    'name' => $call['function']['name'],
                    'arguments' => $call['function']['arguments'],
                ];
            }
        }

        return $input;
    }
}
