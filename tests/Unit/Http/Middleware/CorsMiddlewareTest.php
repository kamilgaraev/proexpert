<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\CorsMiddleware;
use App\Services\Logging\LoggingService;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    public function test_post_too_large_exception_is_forwarded_to_global_handler(): void
    {
        $logging = Mockery::mock(LoggingService::class)->shouldIgnoreMissing();
        $middleware = new CorsMiddleware($logging);
        $request = Request::create(
            '/api/v1/admin/design-management/model-versions/1/derivatives',
            'POST',
            server: ['HTTP_ORIGIN' => 'https://prohelper.pro']
        );

        $this->expectException(PostTooLargeException::class);

        try {
            $middleware->handle($request, static function (): void {
                throw new PostTooLargeException();
            });
        } finally {
            $headers = $request->attributes->get('cors_headers', []);

            self::assertSame('https://prohelper.pro', $headers['Access-Control-Allow-Origin'] ?? null);
            self::assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? null);
        }
    }
}
