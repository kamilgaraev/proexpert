<?php

declare(strict_types=1);

namespace App\Services\Logging;

final class SensitiveDataRedactor
{
    private const REDACTED = '[REDACTED]';

    private const SENSITIVE_KEY_PARTS = [
        'authorization',
        'cookie',
        'password',
        'token',
        'secret',
        'api_key',
        'apikey',
        'private_key',
        'client_secret',
        'refresh_token',
        'access_token',
        'jwt',
        'email',
        'phone',
        'passport',
        'card',
        'bank',
        'inn',
        'address',
        'full_name',
        'plain_password',
    ];

    public function redact(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);
                continue;
            }

            if (is_object($value)) {
                $redacted[$key] = '[Object]';
                continue;
            }

            if (is_string($value) && $this->looksSensitive($value)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    private function isSensitiveKey(string $key): bool
    {
        foreach (self::SENSITIVE_KEY_PARTS as $part) {
            if ($key === $part || str_contains($key, $part)) {
                return true;
            }
        }

        return false;
    }

    private function looksSensitive(string $value): bool
    {
        $patterns = [
            '/Bearer\s+[A-Za-z0-9._\-]+/i',
            '/[?&](token|access_token|refresh_token|signature|X-Amz-Signature)=/i',
            '/\b[A-Za-z0-9_\-.]{48,}\b/',
            '/\b\d{4}\s?\d{4}\s?\d{4}\s?\d{4}\b/',
            '/\b\d{4}\s?\d{6}\b/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
