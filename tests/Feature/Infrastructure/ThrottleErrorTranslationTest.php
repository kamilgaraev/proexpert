<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;

class ThrottleErrorTranslationTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[DataProvider('apiPaths')]
    public function test_rate_limit_error_is_russian_and_keeps_retry_headers(string $path): void
    {
        $request = Request::create($path, 'POST');
        $exception = new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => '60',
            'X-RateLimit-Limit' => '5',
            'X-RateLimit-Remaining' => '0',
        ]);

        $response = $this->app->make(ExceptionHandler::class)->render($request, $exception);
        $payload = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('Слишком много попыток. Попробуйте позже.', $payload['message']);
        self::assertSame('60', $response->headers->get('Retry-After'));
        self::assertSame('5', $response->headers->get('X-RateLimit-Limit'));
        self::assertSame('0', $response->headers->get('X-RateLimit-Remaining'));
        self::assertStringNotContainsString('Too Many Attempts', $response->getContent());
    }

    public static function apiPaths(): array
    {
        return [
            'admin' => ['/api/v1/admin/login'],
            'lk' => ['/api/v1/lk/login'],
            'landing' => ['/api/landing/login'],
            'mobile' => ['/api/v1/mobile/login'],
        ];
    }
}
