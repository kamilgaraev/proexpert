<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

final class TimewebChatCompletionPayloadFactory
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function make(string $model, array $messages, array $options): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];
        $maxTokens = max(1, (int) ($options['max_tokens'] ?? 240));

        if ($this->isGptFive($model)) {
            return [
                ...$payload,
                'max_completion_tokens' => $maxTokens,
            ];
        }

        return [
            ...$payload,
            'max_tokens' => $maxTokens,
            'temperature' => (float) ($options['temperature'] ?? 0),
        ];
    }

    private function isGptFive(string $model): bool
    {
        return preg_match('/\Aopenai\/gpt-5(?:[.-][a-z0-9._-]+)?\z/i', trim($model)) === 1;
    }
}
