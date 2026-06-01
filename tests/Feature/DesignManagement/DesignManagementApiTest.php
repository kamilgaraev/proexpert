<?php

declare(strict_types=1);

namespace Tests\Feature\DesignManagement;

use App\BusinessModules\Features\DesignManagement\Models\DesignArtifactVersion;
use App\BusinessModules\Features\DesignManagement\Models\DesignModelDerivative;
use App\BusinessModules\Features\DesignManagement\Models\DesignPackage;
use App\BusinessModules\Features\DesignManagement\Services\DesignManagementService;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Storage\FileService;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Support\AdminApiTestContext;
use Tests\TestCase;

final class DesignManagementApiTest extends TestCase
{
    public function test_design_management_routes_require_expected_permissions(): void
    {
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages', 'design-management.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages', 'design-management.create');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/packages/{packageId}', 'design-management.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/packages/{packageId}/models', 'design-management.models.upload');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/derivatives', 'design-management.models.prepare_viewer');
        $this->assertRoutePermission('GET', 'api/v1/admin/design-management/model-versions/{versionId}/viewer', 'design-management.models.view');
        $this->assertRoutePermission('POST', 'api/v1/admin/design-management/model-versions/{versionId}/mark-current', 'design-management.edit');
    }

    public function test_project_manager_can_create_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', [
                'project_id' => $project->id,
                'title' => 'Раздел АР',
                'stage' => 'rd',
                'discipline' => 'architecture',
                'planned_issue_date' => now()->addDays(10)->toDateString(),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.project_id', $project->id);
        $response->assertJsonPath('data.title', 'Раздел АР');
        $response->assertJsonPath('data.status', 'draft');
        $response->assertJsonPath('data.status_label', 'Черновик');
        $response->assertJsonPath('data.derivative.status', 'missing');
        $response->assertJsonPath('data.workflow_summary.models_count', 0);
        $this->assertContains('model_missing', $response->json('data.problem_flags'));
    }

    public function test_project_manager_can_upload_ifc_model(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'discipline' => 'architecture',
                'version_number' => '1',
                'revision' => 'R01',
                'model_date' => now()->toDateString(),
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'Архитектурная модель');
        $response->assertJsonPath('data.source_format', 'ifc');
        $response->assertJsonPath('data.version_number', '1');
        $response->assertJsonPath('data.revision', 'R01');
        $response->assertJsonPath('data.is_current', true);

        $version = DesignArtifactVersion::query()->firstOrFail();
        $this->assertStringStartsWith(
            "org-{$context->organization->id}/pir/projects/{$project->id}/packages/{$packageId}/models/",
            $version->source_file_path
        );
        Storage::disk('s3')->assertExists($version->source_file_path);
    }

    public function test_viewer_endpoint_returns_source_and_derivative_blocks(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);
        $this->uploadDerivative($context, $version);

        $response = $this->withHeaders($context->authHeaders())
            ->getJson("/api/v1/admin/design-management/model-versions/{$version->id}/viewer");

        $response->assertOk();
        $response->assertJsonPath('data.version.id', $version->id);
        $response->assertJsonPath('data.source.mime_type', 'application/octet-stream');
        $response->assertJsonPath('data.derivative.status', 'ready');
        $response->assertJsonPath('data.derivative.viewer_provider', 'thatopen');
        $response->assertJsonPath('data.derivative.derivative_format', 'thatopen_frag');
        $this->assertStringStartsWith('https://files.example.test/', (string) $response->json('data.source.download_url'));
        $this->assertStringStartsWith('https://files.example.test/', (string) $response->json('data.derivative.download_url'));
        $this->assertArrayNotHasKey('path', $response->json('data.source'));
    }

    public function test_derivative_upload_accepts_frag_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->uploadDerivative($context, $version);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'ready');
        $response->assertJsonPath('data.viewer_provider', 'thatopen');
        $response->assertJsonPath('data.derivative_format', 'thatopen_frag');

        $derivative = DesignModelDerivative::query()->firstOrFail();
        Storage::disk('s3')->assertExists((string) $derivative->derivative_file_path);
    }

    public function test_derivative_upload_rejects_non_frag_file(): void
    {
        $this->fakeFileStorage();
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $version = $this->uploadModel($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/model-versions/{$version->id}/derivatives", [
                'file' => UploadedFile::fake()->createWithContent('model.txt', 'not a viewer file'),
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['file']);
    }

    public function test_user_from_another_organization_cannot_access_package(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_manager');
        $foreignContext = AdminApiTestContext::create(roleSlug: 'project_manager');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $this->allowAdminAccess();
        $this->allowModuleAccess();
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($foreignContext->authHeaders())
            ->getJson("/api/v1/admin/design-management/packages/{$packageId}");

        $response->assertForbidden();
    }

    public function test_project_viewer_cannot_upload_model(): void
    {
        $context = AdminApiTestContext::create(roleSlug: 'project_viewer');
        $project = Project::factory()->create(['organization_id' => $context->organization->id]);
        $package = DesignPackage::query()->create([
            'organization_id' => $context->organization->id,
            'project_id' => $project->id,
            'created_by' => $context->user->id,
            'updated_by' => $context->user->id,
            'title' => 'Раздел КЖ',
            'status' => 'draft',
            'metadata' => [],
        ]);
        $this->allowAdminAccess(['design-management.models.upload'], ['project_viewer']);
        $this->allowModuleAccess();

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$package->id}/models", [
                'title' => 'Конструктивная модель',
                'version_number' => '1',
                'file' => UploadedFile::fake()->createWithContent('building.ifc', 'IFC'),
            ]);

        $response->assertForbidden();
    }

    private function createPackage(AdminApiTestContext $context, Project $project): int
    {
        $response = $this->withHeaders($context->authHeaders())
            ->postJson('/api/v1/admin/design-management/packages', [
                'project_id' => $project->id,
                'title' => 'Раздел АР',
                'stage' => 'rd',
                'discipline' => 'architecture',
            ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    private function uploadModel(AdminApiTestContext $context, Project $project): DesignArtifactVersion
    {
        $packageId = $this->createPackage($context, $project);

        $response = $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/packages/{$packageId}/models", [
                'title' => 'Архитектурная модель',
                'version_number' => '1',
                'revision' => 'R01',
                'file' => UploadedFile::fake()->createWithContent(
                    'building.ifc',
                    "ISO-10303-21;\nHEADER;\nENDSEC;\nEND-ISO-10303-21;"
                ),
            ]);

        $response->assertCreated();

        return DesignArtifactVersion::query()->latest('id')->firstOrFail();
    }

    private function uploadDerivative(AdminApiTestContext $context, DesignArtifactVersion $version): TestResponse
    {
        return $this->withHeaders($context->authHeaders())
            ->post("/api/v1/admin/design-management/model-versions/{$version->id}/derivatives", [
                'file' => UploadedFile::fake()->createWithContent('model.frag', 'fragment binary'),
                'viewer_provider' => 'thatopen',
                'derivative_format' => 'thatopen_frag',
                'metadata' => [
                    'converted_in_browser' => true,
                ],
            ]);
    }

    private function fakeFileStorage(): void
    {
        Storage::fake('s3');
        $disk = Storage::disk('s3');

        $this->app->forgetInstance(DesignManagementService::class);
        $this->mock(FileService::class, function (MockInterface $mock) use ($disk): void {
            $mock->shouldReceive('disk')->andReturn($disk)->byDefault();
            $mock->shouldReceive('temporaryUrl')->andReturnUsing(
                static fn (?string $path, int $minutes = 5, mixed $organization = null): ?string => $path
                    ? 'https://files.example.test/' . ltrim($path, '/')
                    : null
            )->byDefault();
        });
        $this->app->forgetInstance(DesignManagementService::class);
    }

    private function allowModuleAccess(): void
    {
        $this->mock(AccessController::class, function (MockInterface $mock): void {
            $mock->shouldReceive('hasModuleAccess')->andReturnUsing(
                static fn (int $organizationId, string $moduleSlug): bool => in_array($moduleSlug, [
                    'design-management',
                    'project-management',
                    'file-management',
                ], true)
            );
        });
    }

    private function allowAdminAccess(array $deniedPermissions = [], array $roleSlugs = ['project_manager']): void
    {
        $this->mock(AuthorizationService::class, function (MockInterface $mock) use ($deniedPermissions, $roleSlugs): void {
            $mock->shouldReceive('canAccessInterface')->andReturn(true);
            $mock->shouldReceive('can')->andReturnUsing(
                static fn (User $user, string $permission, ?array $context = null): bool => !in_array($permission, $deniedPermissions, true)
            );
            $mock->shouldReceive('hasRole')->andReturn(true);
            $mock->shouldReceive('getUserRoleSlugs')->andReturn($roleSlugs);
            $mock->shouldReceive('getUserRoles')->andReturnUsing(
                static function (User $user, ?AuthorizationContext $context = null) {
                    return $user->roleAssignments()
                        ->where('is_active', true)
                        ->when($context !== null, static fn ($query) => $query->where('context_id', $context->id))
                        ->get();
                }
            );
        });
    }

    private function assertRoutePermission(string $method, string $uri, string $permission): void
    {
        $route = $this->findRoute($method, $uri);

        $this->assertNotNull($route, "Маршрут {$method} {$uri} не найден.");
        $this->assertContains("authorize:{$permission}", $route->gatherMiddleware(), "{$method} {$uri}");
    }

    private function findRoute(string $method, string $uri): ?LaravelRoute
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === $uri && in_array($method, $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }
}
