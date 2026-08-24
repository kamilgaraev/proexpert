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
    public function test_default_customer_origins_include_all_production_hosts(): void
    {
        self::assertSame([
            'https://customer.1мост.рф',
            'https://customer.xn--1-xtbgmf.xn--p1ai',
            'https://customer.prohelper.pro',
        ], config('web_auth.origins.customer'));
    }

    public function test_rejects_cross_interface_origin_before_request_handler(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/admin/design-management/model-versions/1/derivatives',
            'POST',
            server: ['HTTP_ORIGIN' => 'https://lk.example.test']
        );

        $response = $this->middleware()->handle($request, static function (): never {
            throw new \LogicException('The protected handler must not run.');
        });

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

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

    public function test_allows_customer_preflight_only_from_customer_origin(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/customer/auth/login',
            'OPTIONS',
            server: [
                'HTTP_ORIGIN' => 'https://customer.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
            ]
        );

        $response = $this->middleware()->handle($request, static fn (): Response => response('ok'));

        self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
        self::assertSame('https://customer.example.test', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertSame('Origin', $response->headers->get('Vary'));
    }

    public function test_rejects_public_origin_for_customer_endpoint_with_neutral_error_code(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/customer/auth/login',
            'OPTIONS',
            server: [
                'HTTP_ORIGIN' => 'https://www.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
            ]
        );

        $response = $this->middleware()->handle($request, static function (): never {
            throw new \LogicException('The customer handler must not run.');
        });

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('cors_origin_forbidden', $response->getData(true)['code'] ?? null);
        self::assertSame('Источник запроса не разрешён.', $response->getData(true)['message'] ?? null);
    }

    public function test_rejects_customer_origin_for_landing_endpoint(): void
    {
        $this->configureOrigins();
        $request = Request::create(
            '/api/v1/landing/auth/register',
            'OPTIONS',
            server: [
                'HTTP_ORIGIN' => 'https://customer.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
            ]
        );

        $response = $this->middleware()->handle($request, static fn (): Response => response('ok'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_allows_public_origin_for_registration_and_dadata_but_not_protected_landing_routes(): void
    {
        $this->configureOrigins();

        foreach ([
            '/api/v1/landing/auth/register',
            '/api/v1/landing/dadata/suggest/organizations',
        ] as $path) {
            $request = Request::create($path, 'OPTIONS', server: [
                'HTTP_ORIGIN' => 'https://www.example.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type',
            ]);
            $response = $this->middleware()->handle($request, static fn (): Response => response('ok'));

            self::assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
            self::assertSame('https://www.example.test', $response->headers->get('Access-Control-Allow-Origin'));
            self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        }

        $protected = Request::create('/api/v1/landing/profile', 'OPTIONS', server: [
            'HTTP_ORIGIN' => 'https://www.example.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $response = $this->middleware()->handle($protected, static fn (): Response => response('ok'));

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    private function middleware(): CorsMiddleware
    {
        return new CorsMiddleware(new WebOriginPolicy);
    }

    private function configureOrigins(): void
    {
        config()->set('web_auth.origins.admin', ['https://admin.example.test']);
        config()->set('web_auth.origins.lk', ['https://lk.example.test']);
        config()->set('web_auth.origins.customer', ['https://customer.example.test']);
        config()->set('web_auth.origins.public', ['https://www.example.test']);
    }
}
