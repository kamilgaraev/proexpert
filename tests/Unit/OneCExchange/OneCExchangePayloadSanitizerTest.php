<?php

declare(strict_types=1);

namespace Tests\Unit\OneCExchange;

use App\Services\OneCExchange\Support\OneCExchangePayloadSanitizer;
use PHPUnit\Framework\TestCase;

final class OneCExchangePayloadSanitizerTest extends TestCase
{
    public function test_payload_preview_masks_sensitive_fields_and_removes_raw_diagnostics(): void
    {
        $sanitizer = new OneCExchangePayloadSanitizer();

        $preview = $sanitizer->preview([
            'document_id' => 42,
            'token' => 'secret-token',
            'password' => 'secret-password',
            'bank_account' => '40702810900000000001',
            'payload' => ['raw' => 'sensitive'],
            'stack_trace' => 'Stack trace with SQL select * from users',
            'lines' => [
                ['name' => 'Бетон М350', 'amount' => 120000],
                ['api_key' => 'secret-key', 'name' => 'Арматура'],
            ],
        ]);

        self::assertSame(42, $preview['document_id']);
        self::assertSame('[скрыто]', $preview['token']);
        self::assertSame('[скрыто]', $preview['password']);
        self::assertSame('407028******0001', $preview['bank_account']);
        self::assertArrayNotHasKey('payload', $preview);
        self::assertArrayNotHasKey('stack_trace', $preview);
        self::assertSame('[скрыто]', $preview['lines'][1]['api_key']);
        self::assertSame('Арматура', $preview['lines'][1]['name']);
    }

    public function test_safe_error_keeps_business_message_without_technical_details(): void
    {
        $sanitizer = new OneCExchangePayloadSanitizer();

        $error = $sanitizer->safeError(
            'SQLSTATE[23505]: duplicate key value violates unique constraint users_email_unique in /app/file.php:42',
            'duplicate_delivery'
        );

        self::assertSame('duplicate_delivery', $error['code']);
        self::assertSame('Операция уже была получена ранее.', $error['message']);
        self::assertStringNotContainsString('SQLSTATE', $error['message']);
        self::assertStringNotContainsString('/app/file.php', $error['message']);
    }
}
