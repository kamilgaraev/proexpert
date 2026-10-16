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
        foreach ($messages as $message) {
            if (is_string($message['content'] ?? null)) {
                EstimatorTextPayloadBudget::guard($message['content']);
            }
        }
        $policy = $options['estimate_generation_frozen_text_policy'] ?? null;
        if ($policy !== null && ! is_array($policy)) {
            throw new \DomainException('estimator_text_wire_policy_invalid');
        }
        if ($policy !== null) {
            $policy = EstimatorTextWirePolicy::validate($policy);
        }
        $inputBudgetLimits = $policy === null ? null : EstimatorTextWirePolicy::inputBudgetLimits($policy);
        $prepared = (new TokenBudgetService)->prepare($messages, (array) ($options['tools'] ?? []),
            $policy['profile'] ?? (string) ($options['profile'] ?? 'detailed'), $inputBudgetLimits);
        if ($prepared['messages'] !== array_values($messages)) {
            throw new \DomainException('estimate_text_context_cannot_be_trimmed');
        }
        if ($policy !== null && EstimatorTextWirePolicy::serializedInputUpper($prepared['messages']) > $policy['maximum_serialized_input_bytes']) {
            throw new \DomainException('estimate_text_serialized_input_requires_source_split');
        }

        return [
            'model' => $model,
            'messages' => $prepared['messages'],
            'max_completion_tokens' => min($policy['token_limits']['output_tokens'] ?? $prepared['max_completion_tokens'],
                max(1, (int) ($options['max_completion_tokens'] ?? $options['max_tokens'] ?? 240))),
            'reasoning_effort' => 'none',
        ];
    }
}
