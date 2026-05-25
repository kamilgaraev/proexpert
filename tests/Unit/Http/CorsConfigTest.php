<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CorsConfigTest extends TestCase
{
    #[DataProvider('localDevOriginsProvider')]
    public function test_local_dev_origins_are_allowed(string $origin): void
    {
        $config = require dirname(__DIR__, 3) . '/config/cors.php';

        $this->assertContains($origin, $config['allowed_origins']);
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
}
