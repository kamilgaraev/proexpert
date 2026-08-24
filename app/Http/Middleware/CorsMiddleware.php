<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\CustomerResponse;
use App\Http\Responses\LandingResponse;
use App\Services\Security\WebOriginPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CorsMiddleware
{
    public function __construct(private readonly WebOriginPolicy $origins) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (! is_string($origin) || $origin === '') {
            return $next($request);
        }

        $audience = $this->audienceFor($request);
        $credentials = $audience !== null;
        $allowedOrigins = $audience === null
            ? $this->origins->originsFor('public')
            : $this->origins->originsFor($audience);

        if (! $this->origins->matches($origin, $allowedOrigins)) {
            return $this->forbidden($request);
        }

        $headers = $this->headersFor($origin, $credentials);

        if ($request->isMethod('OPTIONS')) {
            if (! $this->isAllowedPreflight($request)) {
                return $this->forbidden($request);
            }

            return response('', Response::HTTP_NO_CONTENT, $headers);
        }

        $response = $next($request);

        foreach ($headers as $header => $value) {
            $response->headers->set($header, $value);
        }

        return $response;
    }

    private function headersFor(string $origin, bool $credentials): array
    {
        $headers = [
            'Access-Control-Allow-Origin' => $origin,
            'Access-Control-Allow-Methods' => implode(', ', (array) config('cors.allowed_methods', [])),
            'Access-Control-Allow-Headers' => implode(', ', (array) config('cors.allowed_headers', [])),
            'Access-Control-Max-Age' => (string) config('cors.max_age', 86400),
            'Vary' => 'Origin',
        ];

        if ($credentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        $exposedHeaders = (array) config('cors.exposed_headers', []);

        if ($exposedHeaders !== []) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposedHeaders);
        }

        return $headers;
    }

    private function isAllowedPreflight(Request $request): bool
    {
        $requestedMethod = strtoupper((string) $request->header('Access-Control-Request-Method'));
        $allowedMethods = array_map('strtoupper', (array) config('cors.allowed_methods', []));

        if ($requestedMethod === '' || ! in_array($requestedMethod, $allowedMethods, true)) {
            return false;
        }

        $requestedHeaders = array_filter(array_map(
            static fn (string $header): string => strtolower(trim($header)),
            explode(',', (string) $request->header('Access-Control-Request-Headers', '')),
        ));
        $allowedHeaders = array_map(
            static fn (string $header): string => strtolower($header),
            (array) config('cors.allowed_headers', []),
        );

        foreach ($requestedHeaders as $header) {
            if (! in_array($header, $allowedHeaders, true)) {
                return false;
            }
        }

        return true;
    }

    private function forbidden(Request $request): Response
    {
        $message = trans_message('auth.cors.origin_forbidden');
        $extra = ['code' => 'cors_origin_forbidden'];

        if ($request->is('api/v1/customer/*')) {
            return CustomerResponse::error($message, Response::HTTP_FORBIDDEN, extra: $extra);
        }

        return $request->is('api/v1/landing/*') || $request->is('api/lk/*')
            ? LandingResponse::error($message, Response::HTTP_FORBIDDEN, extra: $extra)
            : AdminResponse::error($message, Response::HTTP_FORBIDDEN, extra: $extra);
    }

    private function audienceFor(Request $request): ?string
    {
        if ($request->is('api/v1/admin/*')) {
            return 'admin';
        }

        if ($request->is('api/v1/landing/*') || $request->is('api/lk/*')) {
            return 'lk';
        }

        if ($request->is('api/v1/customer/*')) {
            return 'customer';
        }

        return null;
    }
}
