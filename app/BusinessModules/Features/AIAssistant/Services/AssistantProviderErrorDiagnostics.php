<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

use OpenAI\Exceptions\ErrorException;
use Throwable;

final class AssistantProviderErrorDiagnostics
{
    private const TYPES = [
        'invalid_request_error', 'authentication_error', 'permission_error', 'not_found_error',
        'rate_limit_error', 'server_error', 'api_error', 'insufficient_quota',
    ];

    private const CODES = [
        'invalid_api_key', 'invalid_request_error', 'model_not_found', 'unsupported_parameter',
        'unsupported_value', 'invalid_value', 'missing_required_parameter', 'context_length_exceeded',
        'rate_limit_exceeded', 'insufficient_quota', 'billing_hard_limit_reached', 'billing_not_active',
        'account_deactivated', 'content_policy_violation', 'server_error',
    ];

    public static function fromFailure(?Throwable $failure): array
    {
        $diagnostics = ['provider_http_status' => null, 'provider_error_type' => null, 'provider_error_code' => null];
        if (! $failure instanceof ErrorException) {
            return $diagnostics;
        }

        $status = $failure->getStatusCode();
        if ($status >= 100 && $status <= 599) {
            $diagnostics['provider_http_status'] = $status;
        }
        $diagnostics['provider_error_type'] = self::identifier($failure->getErrorType(...), self::TYPES);
        $diagnostics['provider_error_code'] = self::identifier($failure->getErrorCode(...), self::CODES);

        return $diagnostics;
    }

    private static function identifier(callable $read, array $allowed): ?string
    {
        try {
            $value = $read();

            return is_string($value) && in_array($value, $allowed, true) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
