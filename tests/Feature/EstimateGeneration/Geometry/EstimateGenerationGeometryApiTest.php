<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\ConfirmBuildingGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionOperationalSnapshotBuilder;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\SessionSnapshotEtag;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationGeometryController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewDataSource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\GeometryReviewPayloadReader;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ConfirmEstimateGenerationGeometryRequest;
use App\BusinessModules\Addons\EstimateGeneration\Http\Requests\ShowEstimateGenerationGeometryRequest;
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
use ReflectionClass;

final class EstimateGenerationGeometryApiTest extends LaravelTestCase
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
    public function request_contract_requires_all_three_cas_versions_and_closed_operations(): void
    {
        $rules = (new ConfirmEstimateGenerationGeometryRequest)->rules();

        self::assertContains('required', $rules['state_version']);
        self::assertContains('required', $rules['model_version']);
        self::assertContains('required', $rules['input_version']);
        self::assertSame(['array:op,path,value'], $rules['operations.*']);
        self::assertContains('in:replace', $rules['operations.*.op']);
    }

    #[Test]
    public function geometry_review_route_is_read_only_and_separate_from_confirmation(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/routes.php');

        self::assertIsString($routes);
        self::assertStringContainsString("Route::get('/{session}/geometry'", $routes);
        self::assertStringContainsString("Route::post('/{session}/geometry/confirm'", $routes);
    }

    #[Test]
    public function geometry_review_query_has_bounded_pagination_contract(): void
    {
        self::assertTrue(class_exists(ShowEstimateGenerationGeometryRequest::class));
        $rules = (new ShowEstimateGenerationGeometryRequest)->rules();

        self::assertContains('integer', $rules['sources_page']);
        self::assertContains('min:1', $rules['sources_page']);
        self::assertContains('integer', $rules['sources_per_page']);
        self::assertContains('max:50', $rules['sources_per_page']);
    }

    #[Test]
    public function geometry_review_exposes_mockable_db_less_boundaries(): void
    {
        self::assertTrue(interface_exists(GeometryReviewPayloadReader::class));
        self::assertTrue(interface_exists(GeometryReviewDataSource::class));
    }

    #[Test]
    public function real_http_geometry_get_requires_exact_view_permission_and_standardizes_paginated_payload(): void
    {
        $reader = Mockery::mock(GeometryReviewPayloadReader::class);
        $reader->expects('handle')->once()->with(Mockery::type(EstimateGenerationSession::class), 2, 10)->andReturn([
            'state_version' => 4,
            'model_version' => null,
            'input_version' => null,
            'building_model' => null,
            'sources' => [],
            'sources_meta' => ['total' => 0, 'current_page' => 2, 'per_page' => 10, 'last_page' => 1],
        ]);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->with(
            Mockery::type(User::class),
            'estimate_generation.view',
            ['organization_id' => 9, 'project_id' => 17],
        )->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->mountHttpContract($reader, $this->project(17), $this->makeSession(41, 9, 17));
        $this->actingAs($this->user(9));

        $response = $this->getJson('/_contract/geometry/projects/17/sessions/41?sources_page=2&sources_per_page=10');

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.sources_meta.current_page', 2)
            ->assertJsonPath('data.sources_meta.per_page', 10)
            ->assertJsonMissingPath('data.data');
    }

    #[Test]
    public function real_http_geometry_get_returns_composite_scope_404_before_reading_payload(): void
    {
        $reader = Mockery::mock(GeometryReviewPayloadReader::class);
        $reader->shouldNotReceive('handle');
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->mountHttpContract($reader, $this->project(17), $this->makeSession(41, 9, 18));
        $this->actingAs($this->user(9));

        $this->getJson('/_contract/geometry/projects/17/sessions/41')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    #[Test]
    public function real_http_geometry_get_rejects_invalid_pagination_without_reading_payload(): void
    {
        $reader = Mockery::mock(GeometryReviewPayloadReader::class);
        $reader->shouldNotReceive('handle');
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->expects('can')->once()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->mountHttpContract($reader, $this->project(17), $this->makeSession(41, 9, 17));
        $this->actingAs($this->user(9));

        $this->getJson('/_contract/geometry/projects/17/sessions/41?sources_page=0&sources_per_page=51')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sources_page', 'sources_per_page']);
    }

    #[Test]
    public function snapshot_etag_is_tenant_scoped_and_supports_conditional_semantics(): void
    {
        $etag = SessionSnapshotEtag::forRevision(10, 20, 'revision-1');

        self::assertTrue(SessionSnapshotEtag::matches($etag, $etag));
        self::assertTrue(SessionSnapshotEtag::matches('W/'.$etag, $etag));
        self::assertFalse(SessionSnapshotEtag::matches(SessionSnapshotEtag::forRevision(11, 20, 'revision-1'), $etag));
        self::assertFalse(SessionSnapshotEtag::matches(SessionSnapshotEtag::forRevision(10, 20, 'revision-2'), $etag));
    }

    #[Test]
    public function geometry_response_translation_keys_are_non_empty(): void
    {
        $translations = require dirname(__DIR__, 4).'/lang/ru/estimate_generation.php';

        foreach (['geometry_confirmed', 'geometry_invalid', 'geometry_not_found', 'geometry_error'] as $key) {
            self::assertIsString($translations[$key] ?? null);
            self::assertNotSame('', trim($translations[$key]));
        }
    }

    private function mountHttpContract(GeometryReviewPayloadReader $reader, Project $project, EstimateGenerationSession $session): void
    {
        $confirm = (new ReflectionClass(ConfirmBuildingGeometry::class))->newInstanceWithoutConstructor();
        $snapshot = Mockery::mock(SessionOperationalSnapshotBuilder::class);
        $this->app->instance(EstimateGenerationGeometryController::class, new EstimateGenerationGeometryController(
            $confirm,
            $snapshot,
            $reader,
        ));
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get('/_contract/geometry/projects/{project}/sessions/{session}', [EstimateGenerationGeometryController::class, 'show'])
            ->middleware(SubstituteBindings::class);
    }

    private function user(int $organizationId): User
    {
        $user = new User;
        $user->forceFill(['id' => 3, 'current_organization_id' => $organizationId]);

        return $user;
    }

    private function project(int $id): Project
    {
        $project = new Project;
        $project->forceFill(['id' => $id]);

        return $project;
    }

    private function makeSession(int $id, int $organizationId, int $projectId): EstimateGenerationSession
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
