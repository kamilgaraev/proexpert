<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\Domain\Authorization\Http\Middleware\AuthorizeMiddleware;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class EstimateGenerationRbacTest extends TestCase
{
    public function refreshDatabase(): void {}

    #[DataProvider('protectedActions')]
    public function test_every_estimate_generation_endpoint_has_its_domain_permission(
        string $method,
        string $uri,
        string $permission,
    ): void {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            static fn (LaravelRoute $route): bool => in_array($method, $route->methods(), true)
                && $route->uri() === $uri,
        );

        $this->assertNotNull($route, "{$method} {$uri}");
        $this->assertContains(
            "authorize:{$permission},project,project",
            $route->gatherMiddleware(),
            "{$method} {$uri}",
        );
        $this->assertNotContains('authorize:admin.access', $route->gatherMiddleware(), "{$method} {$uri}");
    }

    public static function protectedActions(): array
    {
        $prefix = 'api/v1/admin/projects/{project}/estimate-generation/sessions';

        return [
            'sessions index' => ['GET', "{$prefix}", 'estimate_generation.view'],
            'sessions create' => ['POST', "{$prefix}", 'estimate_generation.create'],
            'documents index' => ['GET', "{$prefix}/{session}/documents", 'estimate_generation.view'],
            'documents upload' => ['POST', "{$prefix}/{session}/documents", 'estimate_generation.upload_documents'],
            'document show' => ['GET', "{$prefix}/{session}/documents/{document}", 'estimate_generation.view'],
            'document retry' => ['POST', "{$prefix}/{session}/documents/{document}/retry", 'estimate_generation.review'],
            'document ignore' => ['POST', "{$prefix}/{session}/documents/{document}/ignore", 'estimate_generation.review'],
            'analyze' => ['POST', "{$prefix}/{session}/analyze", 'estimate_generation.generate'],
            'generate' => ['POST', "{$prefix}/{session}/generate", 'estimate_generation.generate'],
            'confirm input' => ['POST', "{$prefix}/{session}/confirm-input", 'estimate_generation.review'],
            'retry session' => ['POST', "{$prefix}/{session}/retry", 'estimate_generation.generate'],
            'cancel session' => ['POST', "{$prefix}/{session}/cancel", 'estimate_generation.generate'],
            'archive session' => ['POST', "{$prefix}/{session}/archive", 'estimate_generation.generate'],
            'status' => ['GET', "{$prefix}/{session}/status", 'estimate_generation.view'],
            'packages index' => ['GET', "{$prefix}/{session}/packages", 'estimate_generation.view'],
            'package show' => ['GET', "{$prefix}/{session}/packages/{package}", 'estimate_generation.view'],
            'draft' => ['GET', "{$prefix}/{session}/draft", 'estimate_generation.view'],
            'review items' => ['GET', "{$prefix}/{session}/review-items", 'estimate_generation.view'],
            'session show' => ['GET', "{$prefix}/{session}", 'estimate_generation.view'],
            'export' => ['GET', "{$prefix}/{session}/export", 'estimate_generation.export'],
            'apply' => ['POST', "{$prefix}/{session}/apply", 'estimate_generation.apply'],
            'candidate search' => ['GET', "{$prefix}/{session}/normative-candidates/search", 'estimate_generation.select_normative'],
            'candidate select' => ['POST', "{$prefix}/{session}/normative-candidate", 'estimate_generation.select_normative'],
            'rebuild section' => ['POST', "{$prefix}/{session}/rebuild-section", 'estimate_generation.generate'],
            'feedback' => ['POST', "{$prefix}/{session}/feedback", 'estimate_generation.review'],
        ];
    }

    public function test_normative_statuses_route_deliberately_uses_organization_context(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())->first(
            static fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true)
                && $route->uri() === 'api/v1/admin/estimate-generation/normatives/statuses',
        );

        $this->assertNotNull($route);
        $this->assertContains(
            'authorize:estimate_generation.select_normative,organization,current_organization_id',
            $route->gatherMiddleware(),
        );
    }

    public function test_actual_middleware_allows_project_permission_with_project_context(): void
    {
        [$request, $user] = $this->projectRequest();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with(
            $user,
            'estimate_generation.view',
            ['project_id' => 303, 'organization_id' => 202],
        )->andReturnTrue();

        $response = (new AuthorizeMiddleware($authorization))->handle(
            $request,
            static fn (): Response => new Response('', Response::HTTP_NO_CONTENT),
            'estimate_generation.view',
            'project',
            'project',
        );

        $this->assertSame(Response::HTTP_NO_CONTENT, $response->getStatusCode());
    }

    public function test_actual_middleware_denies_missing_project_permission(): void
    {
        [$request, $user] = $this->projectRequest();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with(
            $user,
            'estimate_generation.apply',
            ['project_id' => 303, 'organization_id' => 202],
        )->andReturnFalse();

        $response = (new AuthorizeMiddleware($authorization))->handle(
            $request,
            static fn (): Response => new Response('', Response::HTTP_NO_CONTENT),
            'estimate_generation.apply',
            'project',
            'project',
        );

        $this->assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function test_estimate_generation_roles_are_explicit_and_viewer_roles_cannot_apply(): void
    {
        $root = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'RoleDefinitions';
        $organizationAdmin = json_decode((string) file_get_contents($root.'/lk/organization_admin.json'), true, 512, JSON_THROW_ON_ERROR);
        $projectAdmin = json_decode((string) file_get_contents($root.'/project/parent_administrator.json'), true, 512, JSON_THROW_ON_ERROR);

        $expected = [
            'estimate_generation.view',
            'estimate_generation.create',
            'estimate_generation.upload_documents',
            'estimate_generation.generate',
            'estimate_generation.review',
            'estimate_generation.select_normative',
            'estimate_generation.export',
            'estimate_generation.apply',
        ];

        $this->assertSame($expected, $organizationAdmin['module_permissions']['estimate-generation']);
        $this->assertSame($expected, $projectAdmin['module_permissions']['estimate-generation']);

        foreach (glob($root.'/*/*.json') ?: [] as $roleFile) {
            if (! str_contains(basename($roleFile), 'viewer') && ! str_contains(basename($roleFile), 'observer')) {
                continue;
            }

            $role = json_decode((string) file_get_contents($roleFile), true, 512, JSON_THROW_ON_ERROR);
            $permissions = array_merge($role['system_permissions'] ?? [], ...array_values($role['module_permissions'] ?? []));
            $this->assertNotContains('estimate_generation.apply', $permissions, $roleFile);
        }
    }

    /** @return array{Request, User} */
    private function projectRequest(): array
    {
        $user = new User(['email' => 'project-admin@example.test', 'current_organization_id' => 202]);
        $user->id = 101;
        $project = new Project;
        $project->id = 303;
        $organization = new Organization;
        $organization->id = 202;
        $request = Request::create('/api/v1/admin/projects/303/estimate-generation/sessions', 'GET');
        $request->setUserResolver(static fn (): User => $user);
        $route = new LaravelRoute('GET', 'api/v1/admin/projects/{project}/estimate-generation/sessions', static fn (): null => null);
        $route->bind($request);
        $route->setParameter('project', $project);
        $request->setRouteResolver(static fn (): LaravelRoute => $route);
        $request->attributes->set('current_organization', $organization);

        return [$request, $user];
    }
}
