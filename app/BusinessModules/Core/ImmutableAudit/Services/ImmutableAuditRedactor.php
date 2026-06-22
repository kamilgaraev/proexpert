<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\ImmutableAudit\Services;

final class ImmutableAuditRedactor
{
    public const REDACTED = '[скрыто]';

    public const POLICY_VERSION = '2026-06-22-v1';

    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'password',
        'plain_password',
        'token',
        'secret',
        'api_key',
        'private_key',
        'client_secret',
        'refresh_token',
        'access_token',
        'jwt',
        'card',
        'bank_account',
        'passport',
        'phone',
        'email',
        'inn',
        'address',
        'full_name',
    ];

    public function redact(array $payload): array
    {
        return $this->redactWithPaths($payload)['payload'];
    }

    public function redactWithPaths(array $payload, string $prefix = ''): array
    {
        $sensitiveFields = [];
        $redacted = [];

        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
            $normalizedKey = mb_strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                $redacted[$key] = self::REDACTED;
                $sensitiveFields[] = $path;

                continue;
            }

            if (is_array($value)) {
                $result = $this->redactWithPaths($value, $path);
                $redacted[$key] = $result['payload'];
                $sensitiveFields = array_merge($sensitiveFields, $result['sensitive_fields']);

                continue;
            }

            if (is_string($value) && $this->looksSensitive($value)) {
                $redacted[$key] = self::REDACTED;
                $sensitiveFields[] = $path;

                continue;
            }

            $redacted[$key] = $value;
        }

        return [
            'payload' => $redacted,
            'sensitive_fields' => array_values(array_unique($sensitiveFields)),
        ];
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

        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value) === 1) {
            return true;
        }

        return preg_match('/(?:\+7|8)?[\s\-()]?\d{3}[\s\-()]?\d{3}[\s\-]?\d{2}[\s\-]?\d{2}/', $value) === 1;
    }
}
