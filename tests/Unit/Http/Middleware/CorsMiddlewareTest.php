<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\CorsMiddleware;
use App\Services\Security\WebOriginPolicy;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class CorsMiddlewareTest extends TestCase
{
    public function test_rejects_cross_interface_origin_before_request_handler(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/admin/design-management/model-versions/1/derivatives',
            'POST',
<<<<<<< HEAD
            server: ['HTTP_ORIGIN' => 'https://lk.example.test']
=======
            server: ['HTTP_ORIGIN' => 'https://1мост.рф']
>>>>>>> fix/glitchtip-257-upload-error-reporting
        );

        $response = $this->middleware()->handle($request, static function (): never {
            throw new \LogicException('The protected handler must not run.');
        });

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

<<<<<<< HEAD
    public function test_allows_matching_interface_origin_and_credentials(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/admin/design-management/model-versions/1/derivatives',
            'POST',
            server: ['HTTP_ORIGIN' => 'https://admin.example.test']
        );

        $response = $this->middleware()->handle($request, static fn (): Response => response('ok'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame('https://admin.example.test', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function test_rejects_preflight_with_unapproved_header(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/landing/auth/login',
            'OPTIONS',
            server: [
                'HTTP_ORIGIN' => 'https://lk.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'X-Forbidden-Header',
            ]
        );

        $response = $this->middleware()->handle($request, static fn (): Response => response('ok'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    private function middleware(): CorsMiddleware
    {
        return new CorsMiddleware(new WebOriginPolicy());
    }

    private function configureOrigins(): void
    {
        config()->set('web_auth.origins.admin', ['https://admin.example.test']);
        config()->set('web_auth.origins.lk', ['https://lk.example.test']);
        config()->set('web_auth.origins.public', ['https://www.example.test']);
=======
            self::assertSame('https://1мост.рф', $headers['Access-Control-Allow-Origin'] ?? null);
            self::assertSame('true', $headers['Access-Control-Allow-Credentials'] ?? null);
        }
>>>>>>> fix/glitchtip-257-upload-error-reporting
    }
}
