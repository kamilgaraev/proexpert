<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Egress;

use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantBudgetExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantEgressDenied;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantProviderFailure;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestCancelled;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantRequestDeadlineExceeded;
use App\BusinessModules\Features\AIAssistant\Exceptions\AssistantResponseIncomplete;
use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use ReflectionProperty;
use Throwable;

final class OrdinaryAssistantExceptionBoundary
{
    public static function sanitize(Throwable $failure): Throwable
    {
        $previous = ini_get('zend.exception_ignore_args');
        $changed = function_exists('ini_set') && @ini_set('zend.exception_ignore_args', '1') !== false;
        try {
            $safe = match (true) {
                $failure instanceof AssistantEgressDenied => new AssistantEgressDenied($failure->reason),
                $failure instanceof AssistantRequestCancelled => new AssistantRequestCancelled,
                $failure instanceof AssistantRequestDeadlineExceeded => new AssistantRequestDeadlineExceeded,
                $failure instanceof AssistantBudgetExceeded => new AssistantBudgetExceeded,
                $failure instanceof AssistantResponseIncomplete => new AssistantResponseIncomplete(
                    ['incomplete_reason' => $failure->reason] + self::usage($failure->providerUsage)),
                $failure instanceof AuthorizationException => (new AuthorizationException(trans_message('ai_assistant.access_denied')))
                    ->withStatus(is_int($failure->status()) && $failure->status() >= 400 && $failure->status() <= 599 ? $failure->status() : null),
                $failure instanceof InvalidArgumentException => new InvalidArgumentException('assistant_request_invalid'),
                default => new AssistantProviderFailure,
            };
            if (array_filter($safe->getTrace(), static fn (array $frame): bool => array_key_exists('args', $frame)) !== []) {
                (new ReflectionProperty(Exception::class, 'trace'))->setValue($safe, []);
            }

            return $safe;
        } finally {
            if ($changed && $previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }

    private static function usage(array $usage): array
    {
        $safe = [];
        foreach (['input_tokens', 'output_tokens', 'tokens_used', 'route_attempt'] as $key) {
            if (is_int($usage[$key] ?? null) && $usage[$key] >= 0) {
                $safe[$key] = $usage[$key];
            }
        }
        if (is_bool($usage['route_fallback'] ?? null)) {
            $safe['route_fallback'] = $usage['route_fallback'];
        }

        return $safe;
    }
}
