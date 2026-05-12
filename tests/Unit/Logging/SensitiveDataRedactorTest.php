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
}
