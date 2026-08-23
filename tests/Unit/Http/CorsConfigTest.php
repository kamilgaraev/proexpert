<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CorsConfigTest extends TestCase
{
    #[DataProvider('localDevOriginsProvider')]
    public function test_local_dev_origins_are_not_implicitly_allowed(string $origin): void
    {
        $config = require dirname(__DIR__, 3).'/config/cors.php';

        $this->assertNotContains($origin, $config['allowed_origins']);
        $this->assertFalse($config['allow_any_origin_in_dev']);
    }

    public static function localDevOriginsProvider(): array
    {
        return [
            'admin localhost' => ['http://localhost:3000'],
            'admin loopback' => ['http://127.0.0.1:3000'],
            'lk localhost' => ['http://localhost:3001'],
            'lk loopback' => ['http://127.0.0.1:3001'],
        ];
    }

    public function test_estimate_generation_snapshot_headers_are_cors_accessible(): void
    {
        $config = require dirname(__DIR__, 3).'/config/cors.php';

        self::assertContains('If-None-Match', $config['allowed_headers']);
        self::assertContains('ETag', $config['exposed_headers']);
    }

    public function test_idempotent_mutation_header_is_cors_accessible(): void
    {
        $config = require dirname(__DIR__, 3).'/config/cors.php';

        self::assertContains('Idempotency-Key', $config['allowed_headers']);
    }
}
