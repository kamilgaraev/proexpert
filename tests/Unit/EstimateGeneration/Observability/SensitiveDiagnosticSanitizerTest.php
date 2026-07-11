<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Observability;

use App\BusinessModules\Addons\EstimateGeneration\Observability\SensitiveDiagnosticSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SensitiveDiagnosticSanitizerTest extends TestCase
{
    #[Test]
    public function it_keeps_only_values_from_closed_per_key_domains(): void
    {
        $result = (new SensitiveDiagnosticSanitizer)->sanitize([
            'provider_code' => 'timeout', 'http_class' => '5xx', 'http_code' => 503,
            'retry_after_seconds' => 15, 'attempt' => 2, 'status' => 'connection_failed',
            'failure_fingerprint' => 'sha256:'.str_repeat('a', 64),
            'nested' => ['safe_code' => 'must_not_survive'], 'unknown' => 'value',
            'prompt' => 'document text', 'Authorization' => 'Bearer secret',
        ]);

        self::assertSame([
            'provider_code' => 'timeout', 'http_class' => '5xx', 'http_code' => 503,
            'retry_after_seconds' => 15, 'attempt' => 2, 'status' => 'connection_failed',
            'failure_fingerprint' => 'sha256:'.str_repeat('a', 64),
        ], $result);
    }

    #[Test]
    #[DataProvider('secretLikeValues')]
    public function it_fails_closed_for_secret_entropy_prefixes_paths_and_tokens(string $value): void
    {
        self::assertSame([], (new SensitiveDiagnosticSanitizer)->sanitize(['provider_code' => $value]));
    }

    /** @return iterable<string, array{string}> */
    public static function secretLikeValues(): iterable
    {
        yield 'bearer' => ['bearer_secret'];
        yield 'jwt' => ['eyJhbGciOiJIUzI1NiJ9.payload.signature'];
        yield 'aws' => ['AKIAIOSFODNN7EXAMPLE'];
        yield 'github' => ['ghp_1234567890abcdefghijkl'];
        yield 'openai' => ['sk-1234567890abcdefghijkl'];
        yield 'unix path' => ['/var/private/file'];
        yield 'windows path' => ['C:\\private\\file'];
        yield 'mixed entropy' => ['aB3dE5fG7hJ9kL1mN3pQ5rS7'];
        yield 'too long' => [str_repeat('a', 81)];
        yield 'document text' => ['full document text'];
    }

    #[Test]
    public function it_rejects_wrong_scalar_types_and_out_of_range_numbers(): void
    {
        self::assertSame([], (new SensitiveDiagnosticSanitizer)->sanitize([
            'http_code' => '503', 'attempt' => 0, 'retry_after_seconds' => 86401,
            'status' => ['connection_failed'], 'safe_code' => new \stdClass,
        ]));
    }
}
