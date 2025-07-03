<?php

namespace App\Services\OpenApi;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

class OpenApiDiffService
{
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
} 