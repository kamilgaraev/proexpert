<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\SensitiveDiagnosticSanitizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SensitiveDiagnosticSanitizerTest extends TestCase
{
    #[Test]
    public function it_keeps_only_closed_safe_fields_and_redacts_sensitive_values_recursively(): void
    {
        $sanitizer = new SensitiveDiagnosticSanitizer(maxDepth: 3, maxItems: 8, maxStringLength: 32);

        $result = $sanitizer->sanitize([
            'provider_code' => 'timeout',
            'http_class' => '5xx',
            'retry_after_seconds' => 15,
            'Authorization' => 'Bearer secret-token',
            'api_KEY' => 'secret-key',
            'prompt' => 'full document text',
            'nested' => [
                'status' => 'temporarily_unavailable',
                'content' => 'private drawing',
            ],
        ]);

        self::assertSame('timeout', $result['provider_code']);
        self::assertSame('5xx', $result['http_class']);
        self::assertSame(15, $result['retry_after_seconds']);
        self::assertSame('[REDACTED]', $result['authorization']);
        self::assertSame('[REDACTED]', $result['api_key']);
        self::assertArrayNotHasKey('prompt', $result);
        self::assertSame(['status' => 'temporarily_unavailable'], $result['nested']);
    }

    #[Test]
    public function it_rejects_token_like_substrings_and_bounds_untrusted_structures(): void
    {
        $sanitizer = new SensitiveDiagnosticSanitizer(maxDepth: 2, maxItems: 3, maxStringLength: 16);
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);

        $result = $sanitizer->sanitize([
            'provider_code' => 'Bearer abcdefghijklmnop',
            'status' => str_repeat('x', 100),
            'nested' => ['safe_code' => 'ok', 'deep' => ['status' => 'hidden']],
            'binary' => "\x00\x01",
            'object' => new \stdClass,
            'resource' => $resource,
        ]);

        fclose($resource);

        self::assertSame('[REDACTED]', $result['provider_code']);
        self::assertSame(16, strlen($result['status']));
        self::assertSame(['safe_code' => 'ok'], $result['nested']);
        self::assertCount(3, $result);
    }
}
