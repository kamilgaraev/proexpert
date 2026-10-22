<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use App\Services\Logging\SensitiveDataRedactor;
use PHPUnit\Framework\TestCase;

final class SensitiveDataRedactorTest extends TestCase
{
    public function test_redacts_sensitive_keys_recursively(): void
    {
        $redactor = new SensitiveDataRedactor();

        $result = $redactor->redact([
            'email' => 'user@example.com',
            'payload' => [
                'password' => 'secret',
                'authorization' => 'Bearer abc.def.ghi',
                'document' => [
                    'passport_number' => '1234 567890',
                ],
            ],
            'safe_count' => 5,
        ]);

        $this->assertSame('[REDACTED]', $result['email']);
        $this->assertSame('[REDACTED]', $result['payload']['password']);
        $this->assertSame('[REDACTED]', $result['payload']['authorization']);
        $this->assertSame('[REDACTED]', $result['payload']['document']['passport_number']);
        $this->assertSame(5, $result['safe_count']);
    }

    public function test_redacts_sensitive_tokens_inside_strings(): void
    {
        $redactor = new SensitiveDataRedactor();

        $result = $redactor->redact([
            'url' => 'https://example.test/reset-password?token=abcdef1234567890abcdef1234567890',
            'message' => 'Authorization: Bearer abc.def.ghi',
        ]);

        $this->assertSame('[REDACTED]', $result['url']);
        $this->assertSame('[REDACTED]', $result['message']);
    }

    public function test_sensitive_keys_preserve_literal_substring_and_binary_classification(): void
    {
        $redactor = new SensitiveDataRedactor();
        $parts = ['authorization', 'cookie', 'password', 'token', 'secret', 'api_key', 'apikey',
            'private_key', 'client_secret', 'refresh_token', 'access_token', 'jwt', 'email', 'phone',
            'passport', 'card', 'bank', 'inn', 'address', 'full_name', 'plain_password'];
        $keys = ['', '0', 'user_id', 'organization_id', 'permission', 'cardinality', 'bankrupt',
            'beginning', "key\0token", 'ключ', 'КЛЮЧ_TOKEN', str_repeat('x', 8192),
            str_repeat('x', 8192).'password', "\xC3\x28key", "\xFFtoken\x80"];

        foreach ($parts as $part) {
            $keys[] = $part;
            $keys[] = $part.'_suffix';
            $keys[] = 'prefix_'.$part;
            $keys[] = 'prefix_'.strtoupper($part).'_suffix';
        }
        for ($byte = 0; $byte < 256; $byte++) {
            $keys[] = chr($byte).'key'.chr(255 - $byte);
        }

        foreach ($keys as $index => $key) {
            $sensitive = false;
            $normalized = mb_strtolower($key);
            foreach ($parts as $part) {
                if (str_contains($normalized, $part)) {
                    $sensitive = true;
                    break;
                }
            }
            $result = $redactor->redact([$key => 17]);

            $this->assertSame($sensitive ? '[REDACTED]' : 17, reset($result), 'key case '.$index);
            $this->assertSame(array_key_first([$key => 17]), array_key_first($result));
        }
    }

    public function test_sensitive_keys_remain_redacted_when_pcre_budget_is_exhausted(): void
    {
        $redactor = new SensitiveDataRedactor();
        $this->assertSame(['password' => '[REDACTED]'], $redactor->redact(['password' => 17]));
        $previousJit = ini_get('pcre.jit');
        $previousLimit = ini_get('pcre.backtrack_limit');

        try {
            ini_set('pcre.jit', '0');
            ini_set('pcre.backtrack_limit', '0');

            $this->assertFalse(preg_match('/(?:password|token)/', 'prefix_password_suffix'));
            $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, preg_last_error());
            $fallback = $redactor->redact(['prefix_password_suffix' => 17]);
            $fallbackError = preg_last_error();
            $this->assertSame(PREG_BACKTRACK_LIMIT_ERROR, $fallbackError);
            $this->assertSame(['prefix_password_suffix' => '[REDACTED]'], $fallback);
            $this->assertSame([
                'prefix_password_suffix' => '[REDACTED]',
                'nested' => ['prefix_token_suffix' => '[REDACTED]', 'ordinary' => 23],
                'ordinary' => 29,
            ], $redactor->redact([
                'prefix_password_suffix' => 17,
                'nested' => ['prefix_token_suffix' => 19, 'ordinary' => 23],
                'ordinary' => 29,
            ]));
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previousLimit);
            ini_set('pcre.jit', (string) $previousJit);
        }
    }

    public function test_nested_payload_stays_clean_after_repeated_redaction(): void
    {
        $redactor = new SensitiveDataRedactor();
        $payload = [
            'event' => 'permission_check',
            'result' => true,
            'context' => [
                'permission' => 'construction-journal.view',
                'password' => 'synthetic',
                'url' => 'https://example.test/?token=synthetic',
                'message' => 'Bearer synthetic-token',
                'opaque' => str_repeat('a', 64),
                'object' => new \stdClass(),
                'values' => [null, false, 0, 1.25],
            ],
        ];
        $expected = [
            'event' => 'permission_check',
            'result' => true,
            'context' => [
                'permission' => 'construction-journal.view',
                'password' => '[REDACTED]',
                'url' => '[REDACTED]',
                'message' => '[REDACTED]',
                'opaque' => '[REDACTED]',
                'object' => '[Object]',
                'values' => [null, false, 0, 1.25],
            ],
        ];

        $first = $redactor->redact($payload);
        $this->assertSame($expected, $first);
        $this->assertSame($expected, $redactor->redact($first));
    }
}
