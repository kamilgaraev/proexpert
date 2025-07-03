<?php

namespace App\Services\OpenApi;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Foundation\Http\FormRequest;
use App\Services\OpenApi\FormRequestToSchemaConverter;
use App\Services\OpenApi\SimpleSchemaComparator;
use App\Services\OpenApi\DtoToSchemaConverter;
use App\Services\OpenApi\ResourceToSchemaConverter;

class OpenApiDiffService
{
    private array $operations = [];
    private array $componentSchemas = [];

    public function __construct()
    {
        $this->loadOpenApiOperations();
        $this->loadComponentSchemas();
    }

    public function getDocumentedRoutes(): Collection
    {
        $baseDir = base_path('docs/openapi');
        $routes = collect();
        foreach (File::allFiles($baseDir) as $file) {
            if ($file->getFilename() !== 'openapi.yaml') {
                continue;
            }
            $openapi = Yaml::parseFile($file->getPathname());
            $servers = $openapi['servers'] ?? [];
            $prefix = isset($servers[0]['url']) ? rtrim($servers[0]['url'], '/') : '';
            $pathsDir = dirname($file->getPathname()) . '/paths';
            if (!File::isDirectory($pathsDir)) {
                continue;
            }
            foreach (File::allFiles($pathsDir) as $pathFile) {
                if (!in_array($pathFile->getExtension(), ['yaml', 'yml'])) {
                    continue;
                }
                $data = Yaml::parseFile($pathFile->getPathname());
                if (!is_array($data)) {
                    continue;
                }
                foreach ($data as $path => $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    foreach ($item as $method => $operation) {
                        $method = strtoupper($method);
                        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])) {
                            continue;
                        }
                        $normalizedPath = preg_replace('/\{[^}]+\}/', '{param}', $prefix . '/' . ltrim($path, '/'));
                        $canonicalMethod = $method === 'PATCH' ? 'PUT' : $method;
                        $routes->push($canonicalMethod . ' ' . $normalizedPath);
                    }
                }
            }
        }
        return $routes->unique();
    }

    public function getLaravelApiRoutes(): Collection
    {
        $routes = collect(Route::getRoutes())->filter(fn($r) => str_starts_with($r->uri(), 'api/'));
        $result = collect();
        foreach ($routes as $route) {
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') {
                    continue;
                }
                $canonicalMethod = $method === 'PATCH' ? 'PUT' : $method;
                $normalizedPath = preg_replace('/\{[^}]+\}/', '{param}', '/' . $route->uri());
                $result->push($canonicalMethod . ' ' . $normalizedPath);
            }
        }
        return $result->unique();
    }

    public function diff(): array
    {
        $docs = $this->getDocumentedRoutes();
        $app = $this->getLaravelApiRoutes();
        return [
            'undocumented' => $app->diff($docs)->values()->all(),
            'obsolete' => $docs->diff($app)->values()->all(),
        ];
    }

    private function loadOpenApiOperations(): void
    {
        $baseDir = base_path('docs/openapi');
        foreach (File::allFiles($baseDir) as $file) {
            if ($file->getFilename() !== 'openapi.yaml') {
                continue;
            }

            $openapi = Yaml::parseFile($file->getPathname());
            $servers = $openapi['servers'] ?? [];
            $prefix = isset($servers[0]['url']) ? rtrim($servers[0]['url'], '/') : '';

            $pathsDir = dirname($file->getPathname()) . '/paths';
            if (!File::isDirectory($pathsDir)) {
                continue;
            }

            foreach (File::allFiles($pathsDir) as $pathFile) {
                if (!in_array($pathFile->getExtension(), ['yaml', 'yml'])) {
                    continue;
                }
                $data = Yaml::parseFile($pathFile->getPathname());
                foreach ($data as $path => $item) {
                    foreach ($item as $method => $operation) {
                        $method = strtoupper($method);
                        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'])) {
                            continue;
                        }

                        $canonicalMethod = $method === 'PATCH' ? 'PUT' : $method;
                        $normalizedPath = preg_replace('/\{[^}]+\}/', '{param}', $prefix . '/' . ltrim($path, '/'));
                        $key = $canonicalMethod . ' ' . $normalizedPath;
                        $this->operations[$key] = $operation;
                    }
                }
            }
        }
    }

    private function loadComponentSchemas(): void
    {
        $baseDir = base_path('docs/openapi');
        foreach (File::allFiles($baseDir) as $file) {
            if (!in_array($file->getExtension(), ['yaml', 'yml'])) {
                continue;
            }

            $normalizedPath = str_replace('\\', '/', $file->getPathname());
            if (!str_contains($normalizedPath, '/components/schemas/')) {
                continue;
            }

            $schema = Yaml::parseFile($file->getPathname());
            $name = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            $this->componentSchemas[$name] = $schema;
        }
    }

    /**
     * Глубокое сравнение тел запросов / ответов.
     */
    public function deepDiff(bool $requests = true, bool $responses = true): array
    {
        $result = [];

        if ($requests) {
            $result['requests'] = $this->diffRequests();
        }

        if ($responses) {
            $result['components'] = $this->diffDtos();
            $result['responses'] = $this->diffResources();
        }

        return $result;
    }

    private function diffRequests(): array
    {
        $converter = new FormRequestToSchemaConverter();
        $comparator = new SimpleSchemaComparator();

        $routes = collect(Route::getRoutes())->filter(fn($r) => str_starts_with($r->uri(), 'api/'));

        $report = [];

        foreach ($routes as $route) {
            $methodKeyed = collect($route->methods())->filter(fn($m) => $m !== 'HEAD')->map(fn($m) => $m === 'PATCH' ? 'PUT' : $m);
            foreach ($methodKeyed as $method) {
                $normalizedPath = preg_replace('/\{[^}]+\}/', '{param}', '/' . $route->uri());
                $key = $method . ' ' . $normalizedPath;

                // Controller + FormRequest
                $action = $route->getAction('uses');
                if (!$action || !is_string($action) || !str_contains($action, '@')) {
                    continue;
                }
                [$ctrlClass, $ctrlMethod] = explode('@', $action);
                if (!$this->safeClassExists($ctrlClass)) {
                    continue;
                }

                $reflection = new \ReflectionMethod($ctrlClass, $ctrlMethod);
                $formRequestClass = null;
                foreach ($reflection->getParameters() as $param) {
                    $type = $param->getType();
                    if ($type && !$type->isBuiltin()) {
                        $paramClass = $type instanceof \ReflectionNamedType ? $type->getName() : null;
                        if ($paramClass && is_subclass_of($paramClass, FormRequest::class)) {
                            $formRequestClass = $paramClass;
                            break;
                        }
                    }
                }

                if (!$formRequestClass) {
                    continue; // GET без body
                }

                // Build FormRequest instance безопасно
                try {
                    $reqInstance = app($formRequestClass);
                } catch (\Throwable $e) {
                    $reqInstance = new $formRequestClass();
                }

                $formSchema = $converter->convert($reqInstance);

                $operation = $this->operations[$key] ?? null;
                if (!$operation) {
                    continue; // Пока нет в доке, уже ловится route diff
                }

                $specSchema = $operation['requestBody']['content']['application/json']['schema'] ?? [];

                $cmp = $comparator->diff($formSchema, $specSchema);

                if ($cmp['missing'] || $cmp['obsolete'] || $cmp['mismatched']) {
                    $scope = $this->detectScopeFromRoute($normalizedPath);
                    $report[$key] = array_merge($cmp, ['scope' => $scope]);
                }
            }
        }

        return $report;
    }

    private function diffDtos(): array
    {
        $converter = new DtoToSchemaConverter();
        $comparator = new SimpleSchemaComparator();

        $dtoDirs = [
            app_path('DataTransferObjects'),
            app_path('DTOs'),
        ];

        $report = [];

        foreach ($dtoDirs as $dir) {
            if (!File::isDirectory($dir)) {
                continue;
            }
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $class = 'App\\' . str_replace(['/', '.php', '\\'], ['\\', '', '\\'], $relative);

                if (!$this->safeClassExists($class)) {
                    continue;
                }

                $dtoSchema = $converter->convert($class);
                $name = class_basename($class);
                $specSchema = $this->componentSchemas[$name] ?? [];

                $scope = $this->detectScopeFromClass($class);

                if (!$specSchema) {
                    $report[$name] = [
                        'missing_in_spec' => true,
                        'scope' => $scope,
                    ];
                    continue;
                }

                $cmp = $comparator->diff($dtoSchema, $specSchema);
                if ($cmp['missing'] || $cmp['obsolete'] || $cmp['mismatched']) {
                    $report[$name] = array_merge($cmp, ['scope' => $scope]);
                }
            }
        }

        return $report;
    }

    private function diffResources(): array
    {
        $converter = new ResourceToSchemaConverter();
        $comparator = new SimpleSchemaComparator();

        $resourceDir = app_path('Http/Resources');
        if (!File::isDirectory($resourceDir)) {
            return [];
        }

        $report = [];

        foreach (File::allFiles($resourceDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace(app_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $class = 'App\\' . str_replace(['/', '.php', '\\'], ['\\', '', '\\'], $relative);

            if (!$this->safeClassExists($class)) {
                continue;
            }

            $schema = $converter->convert($class);
            if (!$schema) {
                continue;
            }

            $basename = class_basename($class);
            // Интуитивно: убираем суффикс Resource
            $componentName = str_ends_with($basename, 'Resource') ? substr($basename, 0, -8) : $basename;

            $specSchema = $this->componentSchemas[$componentName] ?? ($this->componentSchemas[$basename] ?? []);

            $scope = $this->detectScopeFromClass($class);

            if (!$specSchema) {
                $report[$basename] = ['missing_in_spec' => true, 'scope' => $scope];
                continue;
            }

            $cmp = $comparator->diff($schema, $specSchema);
            if ($cmp['missing'] || $cmp['obsolete'] || $cmp['mismatched']) {
                $report[$basename] = array_merge($cmp, ['scope' => $scope]);
            }
        }

        return $report;
    }

    private function safeClassExists(string $class): bool
    {
        try {
            return class_exists($class);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function detectScopeFromRoute(string $path): string
    {
        return $this->detectScopeStr($path);
    }

    private function detectScopeFromClass(string $class): string
    {
        return $this->detectScopeStr($class);
    }

    private function detectScopeStr(string $str): string
    {
        $lower = strtolower($str);
        if (str_contains($lower, '/admin/') || str_contains($lower, '\\admin\\') || str_contains($lower, 'admin')) {
            return 'admin';
        }
        if (str_contains($lower, '/landing/') || str_contains($lower, '/lk/') || str_contains($lower, '\\landing\\') || str_contains($lower, 'landing') || str_contains($lower, 'lk')) {
            return 'lk';
        }
        if (str_contains($lower, '/mobile/') || str_contains($lower, '\\mobile\\') || str_contains($lower, 'mobile')) {
            return 'mobile';
        }
        return 'common';
    }
} 