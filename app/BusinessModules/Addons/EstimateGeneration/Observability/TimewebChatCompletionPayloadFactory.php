<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use App\Support\AI\LunaModelPolicy;
use App\Support\AI\TokenBudgetService;

final class TimewebChatCompletionPayloadFactory
{
    /**
     * @param  array<int, array<string, mixed>>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function make(string $model, array $messages, array $options): array
    {
        $model = LunaModelPolicy::assert($model, 'timeweb');
        $prepared = (new TokenBudgetService())->prepare($messages, (array) ($options['tools'] ?? []), (string) ($options['profile'] ?? 'detailed'));
        return [
            'model' => $model,
            'messages' => $prepared['messages'],
            'max_completion_tokens' => min($prepared['max_completion_tokens'], max(1, (int) ($options['max_completion_tokens'] ?? $options['max_tokens'] ?? 240))),
            'reasoning_effort' => 'none',
        ];
    }
}
