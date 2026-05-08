<?php

declare(strict_types=1);

namespace App\Services\Activity;

final class ActivityEventRedactor
{
    private const REDACTED = '[скрыто]';

    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'access_token',
        'refresh_token',
        'api_key',
        'authorization',
        'secret',
        'private_key',
        'card_number',
        'credit_card',
        'passport',
        'email',
        'phone',
        'full_name',
        'address',
    ];

    public function redact(array $data): array
    {
        $redacted = [];

        foreach ($data as $key => $value) {
            $normalizedKey = is_string($key) ? strtolower($key) : (string) $key;

            if ($this->isSensitiveKey($normalizedKey)) {
                $redacted[$key] = self::REDACTED;
                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redact($value);
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
        foreach (self::SENSITIVE_KEYS as $sensitiveKey) {
            if ($key === $sensitiveKey || str_contains($key, $sensitiveKey)) {
                return true;
            }
        }

        return false;
    }

    private function looksSensitive(string $value): bool
    {
        if (preg_match('/Bearer\s+[A-Za-z0-9._\-]+/i', $value) === 1) {
            return true;
        }

        if (preg_match('/\b[A-Za-z0-9_\-.]{48,}\b/', $value) === 1) {
            return true;
        }

        if (preg_match('/\b\d{4}\s?\d{4}\s?\d{4}\s?\d{4}\b/', $value) === 1) {
            return true;
        }

        return false;
    }
}
