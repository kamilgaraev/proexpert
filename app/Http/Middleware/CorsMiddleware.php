<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Responses\AdminResponse;
use App\Http\Responses\LandingResponse;
use App\Services\Security\WebOriginPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CorsMiddleware
{
    public function __construct(private readonly WebOriginPolicy $origins)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->header('Origin');

        if (! is_string($origin) || $origin === '') {
            return $next($request);
        }
<<<<<<< HEAD

        $audience = $this->audienceFor($request);
        $credentials = $audience !== null;
        $allowedOrigins = $audience === null
            ? $this->origins->originsFor('public')
            : $this->origins->originsFor($audience);

        if (! $this->origins->matches($origin, $allowedOrigins)) {
            return $this->forbidden($request);
=======
        
        // Получаем конфигурацию CORS
        $allowedOrigins = Config::get('cors.allowed_origins', []);
        $allowedOriginsPatterns = Config::get('cors.allowed_origins_patterns', []);
        $allowedMethods = Config::get('cors.allowed_methods', ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']);
        $allowedHeaders = Config::get('cors.allowed_headers', ['Content-Type', 'X-Auth-Token', 'Origin', 'Authorization', 'X-Requested-With']);
        $exposedHeaders = Config::get('cors.exposed_headers', []);
        $maxAge = Config::get('cors.max_age', 86400);
        $allowAnyOriginInDev = Config::get('cors.allow_any_origin_in_dev', false);
        
        // Определяем, доступен ли запрошенный origin
        $allowedOrigin = null;
        $allowCredentials = 'false';
        $originMatched = false;
        
        // Если мы в режиме разработки и настройка разрешает любой origin
        if (app()->environment('local') && $allowAnyOriginInDev) {
            $allowedOrigin = $origin ?: '*';
            $allowCredentials = ($allowedOrigin === '*') ? 'false' : 'true';
            $originMatched = true;
        } 
        // Иначе проверяем по списку разрешенных
        else if ($origin) {
            if (in_array($origin, $allowedOrigins)) {
                $allowedOrigin = $origin;
                $allowCredentials = 'true';
                $originMatched = true;
            } else {
                foreach ($allowedOriginsPatterns as $pattern) {
                    if (preg_match($pattern, $origin)) {
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                        break;
                    }
                }
            }
            
            if (!$originMatched) {
                // В режиме разработки можем быть более снисходительными
                if (app()->environment('local')) {
                    // SECURITY: Разрешение неизвестного origin в dev среде
                    $this->logging->security('cors.origin.allowed.dev', [
                        'origin' => $origin,
                        'environment' => 'local',
                        'uri' => $request->getRequestUri()
                    ], 'info');
                    $allowedOrigin = $origin;
                    $allowCredentials = 'true';
                    $originMatched = true;
                } else {
                    // В продакшене для 1мост.рф доменов разрешаем
                    if ($origin && (strpos($origin, '.1мост.рф') !== false || $origin === 'https://1мост.рф')) {
                        // SECURITY: Разрешение 1мост.рф домена не из списка
                        $this->logging->security('cors.origin.allowed.prohelper', [
                            'origin' => $origin,
                            'uri' => $request->getRequestUri(),
                            'auto_approved' => true
                        ], 'info');
                        $allowedOrigin = $origin;
                        $allowCredentials = 'true';
                        $originMatched = true;
                    } else {
                        // SECURITY: КРИТИЧНО - Отклонен запрос с недопустимого origin
                        $this->logging->security('cors.origin.rejected', [
                            'origin' => $origin,
                            'uri' => $request->getRequestUri(),
                            'user_agent' => $request->header('User-Agent'),
                            'ip_address' => $request->ip(),
                            'allowed_origins' => $allowedOrigins,
                            'potential_security_threat' => true
                        ], 'warning');
                        $allowedOrigin = 'null';
                        $allowCredentials = 'false';
                    }
                }
            }
        } else {
            // Если origin не указан, используем wildcard (только для запросов без credentials)
            $allowedOrigin = '*';
            $allowCredentials = 'false';
            $originMatched = true;
>>>>>>> fix/glitchtip-257-upload-error-reporting
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
        $message = trans_message('auth.access_denied');

        return $request->is('api/v1/landing/*') || $request->is('api/lk/*')
            ? LandingResponse::error($message, Response::HTTP_FORBIDDEN)
            : AdminResponse::error($message, Response::HTTP_FORBIDDEN);
    }

    private function audienceFor(Request $request): ?string
    {
        if ($request->is('api/v1/admin/*')) {
            return 'admin';
        }

        if ($request->is('api/v1/landing/*') || $request->is('api/lk/*')) {
            return 'lk';
        }

        return null;
    }
}
