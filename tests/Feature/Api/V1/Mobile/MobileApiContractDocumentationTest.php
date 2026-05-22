<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class MobileApiContractDocumentationTest extends TestCase
{
    public function test_openapi_documents_every_registered_mobile_route(): void
    {
        $pathsDocument = $this->readProjectFile('docs/openapi/mobile/paths/index.yaml');

        foreach ($this->mobileRoutes() as $route) {
            $path = $this->mobilePath($route);
            $block = $this->pathBlock($pathsDocument, $path);

            $this->assertNotSame('', $block, "{$path} is missing from mobile OpenAPI paths.");

            foreach ($this->routeMethods($route) as $method) {
                $this->assertStringContainsString(
                    '  '.strtolower($method).':',
                    $block,
                    "{$method} {$path} is missing from mobile OpenAPI paths."
                );
            }
        }
    }

    public function test_checklist_documents_every_registered_mobile_operation(): void
    {
        $checklist = $this->readProjectFile('docs/mobile/mobile-api-contract-checklist.md');

        foreach ($this->mobileRoutes() as $route) {
            $path = $this->mobilePath($route);

            foreach ($this->routeMethods($route) as $method) {
                $this->assertStringContainsString(
                    '| `'.$method.' '.$path.'` |',
                    $checklist,
                    "{$method} {$path} is missing from mobile contract checklist."
                );
            }
        }
    }

    public function test_mobile_notifications_expose_only_canonical_read_actions(): void
    {
        $notificationRoutes = $this->mobileRoutes()
            ->filter(static fn (LaravelRoute $route): bool => str_contains($route->uri(), 'notifications/{id}/'));

        $this->assertFalse(
            $notificationRoutes->contains(static fn (LaravelRoute $route): bool => $route->uri() === 'api/v1/mobile/notifications/{id}/read')
        );
        $this->assertFalse(
            $notificationRoutes->contains(static fn (LaravelRoute $route): bool => $route->uri() === 'api/v1/mobile/notifications/{id}/unread')
        );
        $this->assertTrue(
            $notificationRoutes->contains(static fn (LaravelRoute $route): bool => $route->uri() === 'api/v1/mobile/notifications/{id}/mark-read')
        );
        $this->assertTrue(
            $notificationRoutes->contains(static fn (LaravelRoute $route): bool => $route->uri() === 'api/v1/mobile/notifications/{id}/mark-unread')
        );
    }

    /**
     * @return Collection<int, LaravelRoute>
     */
    private function mobileRoutes(): Collection
    {
        return collect(Route::getRoutes())
            ->filter(static fn (LaravelRoute $route): bool => str_starts_with($route->uri(), 'api/v1/mobile'))
            ->sortBy(static fn (LaravelRoute $route): string => $route->uri().' '.implode('|', $route->methods()))
            ->values();
    }

    /**
     * @return list<string>
     */
    private function routeMethods(LaravelRoute $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            static fn (string $method): bool => $method !== 'HEAD'
        ));
    }

    private function mobilePath(LaravelRoute $route): string
    {
        return '/'.ltrim(str_replace('api/v1/mobile', '', $route->uri()), '/');
    }

    private function pathBlock(string $document, string $path): string
    {
        $document = str_replace(["\r\n", "\r"], "\n", $document);
        $document = "\n".$document;
        $needle = "\n".$path.":\n";
        $start = strpos($document, $needle);

        if ($start === false) {
            return '';
        }

        $next = strpos($document, "\n/", $start + strlen($needle));

        if ($next === false) {
            return substr($document, $start);
        }

        return substr($document, $start, $next - $start);
    }

    private function readProjectFile(string $path): string
    {
        $contents = file_get_contents(base_path($path));

        $this->assertIsString($contents);

        return $contents;
    }
}
