<?php

declare(strict_types=1);

namespace App\Services\Monitoring;

use Illuminate\Http\Request;
use Sentry\State\Scope;
use Throwable;

class SentryScopeService
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'access_token',
        'refresh_token',
        'authorization',
        'cookie',
        'x-api-key',
        'api_key',
        'secret',
        'client_secret',
    ];

    public function captureException(Throwable $exception, ?Request $request = null): void
    {
        if (!app()->bound('sentry')) {
            return;
        }

        $request ??= app()->bound('request') ? request() : null;

        \Sentry\withScope(function (Scope $scope) use ($exception, $request): void {
            $this->applyExceptionContext($scope, $exception, $request);
            app('sentry')->captureException($exception);
        });
    }

    private function applyExceptionContext(Scope $scope, Throwable $exception, ?Request $request): void
    {
        $scope->setTag('app_env', (string) config('app.env'));
        $scope->setTag('app_debug', config('app.debug') ? 'true' : 'false');
        $scope->setTag('exception_class', $exception::class);

        $release = config('sentry.release');
        if (is_string($release) && $release !== '') {
            $scope->setTag('release', $release);
        }

        if ($request === null) {
            return;
        }

        $route = $request->route();
        $user = $request->user();
        $organizationId = $request->attributes->get('current_organization_id');
        $correlationId = $request->header('X-Correlation-ID')
            ?? $request->header('X-Request-ID')
            ?? $request->header('X-Trace-ID');
        $interface = $this->detectInterface($request);
        $module = $this->detectModule($request);

        $scope->setTag('interface', $interface);
        $scope->setTag('module', $module);
        $scope->setTag('request_method', $request->method());
        $scope->setTag('request_path', '/' . ltrim($request->path(), '/'));
        $scope->setTag('route_name', $route?->getName() ?? 'unknown');

        if ($organizationId !== null) {
            $scope->setTag('organization_id', (string) $organizationId);
        }

        if ($correlationId) {
            $scope->setTag('correlation_id', $correlationId);
        }

        if ($user?->id !== null) {
            $scope->setUser([
                'id' => (string) $user->id,
            ]);
        }

        $scope->setContext('request', [
            'method' => $request->method(),
            'path' => '/' . ltrim($request->path(), '/'),
            'route_name' => $route?->getName(),
            'route_action' => $route?->getActionName(),
            'query' => $this->sanitizeValues($request->query()),
            'input_keys' => array_values(array_keys($this->sanitizeValues($request->all()))),
            'file_keys' => array_values(array_keys($request->allFiles())),
            'content_type' => $request->header('Content-Type'),
            'content_length' => $request->header('Content-Length'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'correlation_id' => $correlationId,
        ]);

        $scope->setContext('application', [
            'environment' => config('app.env'),
            'release' => $release,
            'module' => $module,
            'interface' => $interface,
        ]);

        $scope->setContext('actor', [
            'user_id' => $user?->id,
            'organization_id' => $organizationId,
        ]);
    }

    private function sanitizeValues(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);

            if (in_array($normalizedKey, self::SENSITIVE_KEYS, true)) {
                $sanitized[$key] = '[Filtered]';
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeValues($value);
                continue;
            }

            if (is_object($value)) {
                $sanitized[$key] = '[Object]';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function detectModule(Request $request): string
    {
        $path = $request->path();

        if (preg_match('#api/v1/admin/([^/]+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#api/v1/lk/([^/]+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#api/v1/landing/([^/]+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#api/v1/mobile/([^/]+)#', $path, $matches) === 1) {
            return $matches[1];
        }

        return 'unknown';
    }

    private function detectInterface(Request $request): string
    {
        $path = $request->path();

        return match (true) {
            str_contains($path, 'api/v1/admin') => 'admin',
            str_contains($path, 'api/v1/mobile'), str_contains($path, 'api/mobile') => 'mobile',
            str_contains($path, 'api/v1/landing'), str_contains($path, 'api/lk') => 'lk',
            str_contains($path, 'api/superadmin') => 'superadmin',
            str_contains($path, 'api/holding-api') => 'holding',
            default => 'web',
        };
    }
}
