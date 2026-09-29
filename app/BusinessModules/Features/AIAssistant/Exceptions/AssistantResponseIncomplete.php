<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Exceptions;

use RuntimeException;

final class AssistantResponseIncomplete extends RuntimeException
{
    public readonly string $reason;

    public readonly array $providerUsage;

    public function __construct(array $response)
    {
        $reason = $response['incomplete_reason'] ?? 'max_output_tokens';
        $this->reason = in_array($reason, ['max_output_tokens', 'content_filter'], true) ? $reason : 'unknown';
        $this->providerUsage = array_intersect_key($response, array_flip([
            'input_tokens', 'output_tokens', 'tokens_used', 'provider', 'model', 'profile',
            'route_attempt', 'route_fallback', 'token_calibration',
        ]));
        parent::__construct('ai_assistant_response_incomplete');
    }

    public function messageKey(): string
    {
        return $this->reason === 'max_output_tokens'
            ? 'ai_assistant.output_limit_exceeded'
            : 'ai_assistant.response_incomplete';
    }
}
