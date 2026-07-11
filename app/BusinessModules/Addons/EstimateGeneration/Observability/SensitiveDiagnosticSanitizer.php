<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Observability;

use InvalidArgumentException;

final readonly class SensitiveDiagnosticSanitizer
{
    private const SAFE_KEYS = [
        'provider_code', 'http_class', 'http_code', 'status', 'safe_code',
        'retry_after_seconds', 'attempt', 'operation', 'stage', 'reason',
        'validation_code', 'storage_code', 'claim_status', 'lineage_code', 'nested',
        'failure_fingerprint',
    ];

    private const REDACT_KEYS = [
        'authorization', 'api_key', 'apikey', 'access_token', 'refresh_token', 'token',
        'secret', 'password', 'cookie', 'headers',
    ];

    private const DROP_KEYS = [
        'prompt', 'messages', 'request', 'response', 'body', 'content', 'document',
        'document_text', 'filename', 'file_name', 'path', 'trace', 'exception', 'error_message',
    ];

    public function __construct(
        private int $maxDepth = 4,
        private int $maxItems = 32,
        private int $maxStringLength = 160,
    ) {
        if ($maxDepth < 1 || $maxDepth > 8 || $maxItems < 1 || $maxItems > 128 || $maxStringLength < 8 || $maxStringLength > 512) {
            throw new InvalidArgumentException('Invalid diagnostic sanitizer limits.');
        }
    }

    /** @param array<array-key, mixed> $context @return array<string, mixed> */
    public function sanitize(array $context): array
    {
        return $this->sanitizeMap($context, 1);
    }

    /** @param array<array-key, mixed> $values @return array<string, mixed> */
    private function sanitizeMap(array $values, int $depth): array
    {
        if ($depth > $this->maxDepth) {
            return [];
        }

        $result = [];
        foreach ($values as $rawKey => $value) {
            if (count($result) >= $this->maxItems || ! is_string($rawKey)) {
                break;
            }
            $key = strtolower($rawKey);
            if (in_array($key, self::DROP_KEYS, true)) {
                continue;
            }
            if (in_array($key, self::REDACT_KEYS, true)) {
                $result[$key] = '[REDACTED]';

                continue;
            }
            if (! in_array($key, self::SAFE_KEYS, true)) {
                continue;
            }
            if (is_array($value)) {
                $nested = $this->sanitizeMap($value, $depth + 1);
                if ($nested !== []) {
                    $result[$key] = $nested;
                }

                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $result[$key] = $value;

                continue;
            }
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
                continue;
            }
            $result[$key] = $this->looksSensitive($value) || ! $this->isMachineValue($value)
                ? '[REDACTED]'
                : mb_substr($value, 0, $this->maxStringLength);
        }

        return $result;
    }

    private function looksSensitive(string $value): bool
    {
        return preg_match('/(?:bearer\s+|api[_-]?key\s*[:=]|eyJ[a-zA-Z0-9_-]{8,}\.|sk-[a-zA-Z0-9]{8,})/i', $value) === 1;
    }

    private function isMachineValue(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]*\z/', $value) === 1;
    }
}
