<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationBuildingModelController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\BuildingModelPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\BuildingModelReadDataSource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\Test;

final class EstimateGenerationBuildingModelApiTest extends LaravelTestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function real_http_contract_requires_exact_view_permission_and_returns_admin_response_meta(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->with(
            Mockery::type(User::class),
            'estimate_generation.view',
            ['organization_id' => 9, 'project_id' => 17],
        )->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $source = Mockery::mock(BuildingModelReadDataSource::class);
        $source->expects('latestModel')->once()->with(9, 17, 41)->andReturnNull();
        $this->mount($source, $this->project(17, 9), $this->generationSession(41, 9, 17));
        $this->actingAs($this->user(9));

        $response = $this->getJson('/_contract/building-model/projects/17/sessions/41?quantities_page=2&quantities_per_page=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.building_model', null)
            ->assertJsonPath('data.content_version', null)
            ->assertJsonPath('data.quantities.data', [])
            ->assertJsonPath('data.quantities.meta.total', 0)
            ->assertJsonPath('data.quantities.meta.current_page', 1)
            ->assertJsonPath('data.quantities.meta.per_page', 10)
            ->assertJsonMissingPath('data.model_version')
            ->assertJsonMissingPath('data.data');
    }

    #[Test]
    public function composite_tenant_project_session_mismatch_returns_404_before_reader(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $source = Mockery::mock(BuildingModelReadDataSource::class);
        $source->shouldNotReceive('latestModel');
        $this->mount($source, $this->project(17, 9), $this->generationSession(41, 9, 18));
        $this->actingAs($this->user(9));

        $this->getJson('/_contract/building-model/projects/17/sessions/41')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function invalid_pagination_returns_422_before_reader(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $source = Mockery::mock(BuildingModelReadDataSource::class);
        $source->shouldNotReceive('latestModel');
        $this->mount($source, $this->project(17, 9), $this->generationSession(41, 9, 17));
        $this->actingAs($this->user(9));

        $this->getJson('/_contract/building-model/projects/17/sessions/41?quantities_page=0&quantities_per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantities_page', 'quantities_per_page']);
    }

    #[Test]
    public function foreign_evidence_id_returns_404_and_safe_evidence_response_has_no_private_locator(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->twice()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $source = Mockery::mock(BuildingModelReadDataSource::class);
        $source->expects('evidence')->once()->with(9, 17, 41, 999)->andReturnNull();
        $source->expects('evidence')->once()->with(9, 17, 41, 101)->andReturn([
            'id' => 101,
            'type' => 'measured',
            'source_type' => 'document_unit',
            'source_ref' => 'document:91/private',
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'locator' => ['document_id' => 91, 'page' => 3, 'source_key' => 'private-locator'],
            'value' => ['quantity' => 20, 'unit' => 'm2', 'method' => 'geometry'],
            'confidence' => '0.980000',
            'producer_name' => 'drawing_analyzer',
            'producer_version' => 'model:v1',
            'invalidated_at' => null,
        ]);
        $source->expects('documentNames')->once()->with(9, 17, 41, [91])->andReturn([91 => 'План.pdf']);
        $this->mount($source, $this->project(17, 9), $this->generationSession(41, 9, 17));
        $this->actingAs($this->user(9));

        $this->getJson('/_contract/building-model/projects/17/sessions/41/evidence/999')->assertNotFound();
        $response = $this->getJson('/_contract/building-model/projects/17/sessions/41/evidence/101');

        $response->assertOk()
            ->assertJsonPath('data.document.filename', 'План.pdf')
            ->assertJsonPath('data.preview', null)
            ->assertJsonMissingPath('data.locator')
            ->assertJsonMissingPath('data.source_ref');
        self::assertStringNotContainsString('private-locator', $response->getContent());
    }

    #[Test]
    public function invalidated_evidence_id_returns_404_and_is_never_exposed(): void
    {
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $source = Mockery::mock(BuildingModelReadDataSource::class);
        $source->expects('evidence')->once()->with(9, 17, 41, 101)->andReturn([
            'id' => 101,
            'type' => 'measured',
            'source_type' => 'document_unit',
            'source_ref' => 'document:91/private',
            'source_version' => 'sha256:'.str_repeat('a', 64),
            'locator' => ['document_id' => 91, 'page' => 3],
            'value' => ['quantity' => 20, 'unit' => 'm2', 'method' => 'geometry'],
            'confidence' => '0.980000',
            'producer_name' => 'drawing_analyzer',
            'producer_version' => 'model:v1',
            'invalidated_at' => '2026-07-13T12:00:00+00:00',
        ]);
        $source->shouldNotReceive('documentNames');
        $this->mount($source, $this->project(17, 9), $this->generationSession(41, 9, 17));
        $this->actingAs($this->user(9));

        $this->getJson('/_contract/building-model/projects/17/sessions/41/evidence/101')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function production_routes_are_get_only_and_enforce_view_permission(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/{session}/building-model'", $routes);
        self::assertStringContainsString("Route::get('/{session}/evidence/{evidence}'", $routes);
        self::assertSame(2, substr_count($routes, 'EstimateGenerationBuildingModelController::class'));
        self::assertStringContainsString("middleware('authorize:estimate_generation.view,project,project')", $routes);
    }

    private function mount(BuildingModelReadDataSource $source, Project $project, EstimateGenerationSession $session): void
    {
        $controller = new EstimateGenerationBuildingModelController(new BuildingModelPayloadService($source));
        $this->app->instance(EstimateGenerationBuildingModelController::class, $controller);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/_contract/building-model/projects/{project}/sessions/{session}', [EstimateGenerationBuildingModelController::class, 'show'])
            ->middleware(SubstituteBindings::class);
        Route::get('/_contract/building-model/projects/{project}/sessions/{session}/evidence/{evidence}', [EstimateGenerationBuildingModelController::class, 'evidence'])
            ->middleware(SubstituteBindings::class);
    }

    private function user(int $organizationId): User
    {
        $user = new User;
        $user->forceFill(['id' => 3, 'current_organization_id' => $organizationId]);

        return $user;
    }

    private function project(int $id, int $organizationId): Project
    {
        $project = new Project;
        $project->forceFill(['id' => $id, 'organization_id' => $organizationId]);

        return $project;
    }

    private function generationSession(int $id, int $organizationId, int $projectId): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => $id,
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'state_version' => 4,
        ]);

        return $session;
    }
}
