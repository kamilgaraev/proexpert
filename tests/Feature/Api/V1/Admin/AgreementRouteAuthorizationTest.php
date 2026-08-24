<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Admin;

use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AgreementRouteAuthorizationTest extends TestCase
{
    public function test_project_agreement_routes_require_operation_specific_permissions(): void
    {
        $expected = [
            'GET api/v1/admin/projects/{project}/agreements' => 'authorize:agreements.view,project,project',
            'POST api/v1/admin/projects/{project}/agreements' => 'authorize:agreements.create,project,project',
            'GET api/v1/admin/projects/{project}/agreements/{agreement}' => 'authorize:agreements.view,project,project',
            'PUT api/v1/admin/projects/{project}/agreements/{agreement}' => 'authorize:agreements.edit,project,project',
            'DELETE api/v1/admin/projects/{project}/agreements/{agreement}' => 'authorize:agreements.delete,project,project',
            'POST api/v1/admin/projects/{project}/agreements/{agreement}/apply-changes' => 'authorize:agreements.edit,project,project',
        ];

        foreach ($expected as $routeKey => $middleware) {
            [$method, $uri] = explode(' ', $routeKey, 2);
            $route = collect(Route::getRoutes()->getRoutes())->first(
                static fn (LaravelRoute $candidate): bool => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true)
            );

            self::assertInstanceOf(LaravelRoute::class, $route, $routeKey);
            self::assertContains($middleware, $route->gatherMiddleware(), $routeKey);
        }
    }

    public function test_legacy_unscoped_agreement_mutation_routes_are_not_registered(): void
    {
        $legacyMutations = [
            ['POST', 'api/v1/admin/agreements'],
            ['PUT', 'api/v1/admin/agreements/{agreement}'],
            ['PATCH', 'api/v1/admin/agreements/{agreement}'],
            ['DELETE', 'api/v1/admin/agreements/{agreement}'],
            ['POST', 'api/v1/admin/agreements/{agreement}/apply-changes'],
        ];

        foreach ($legacyMutations as [$method, $uri]) {
            $route = collect(Route::getRoutes()->getRoutes())->first(
                static fn (LaravelRoute $candidate): bool => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true)
            );
            self::assertNull($route, "{$method} {$uri}");
        }
    }
}
