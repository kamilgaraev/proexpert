<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\BuildSessionSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\EstimateGenerationStatus;
use App\BusinessModules\Addons\EstimateGeneration\Http\Controllers\EstimateGenerationController;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionListResource;
use App\BusinessModules\Addons\EstimateGeneration\Http\Resources\EstimateGenerationSessionResource;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\Ocr\DocumentGenerationReadinessService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Quality\EstimatorReadinessService;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Http\Responses\AdminResponse;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class EstimateGenerationWorkflowApiTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 3).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    #[DataProvider('tenantGuardedTransitions')]
    public function foreign_tenant_transition_returns_403_not_500(string $method): void
    {
        $user = new TestPermissionUser(['estimate_generation.review', 'estimate_generation.generate']);
        $user->forceFill(['id' => 5, 'current_organization_id' => 10]);
        $project = new Project;
        $project->id = 20;
        $session = new EstimateGenerationSession([
            'organization_id' => 999,
            'project_id' => 20,
            'status' => EstimateGenerationStatus::Failed,
            'state_version' => 3,
        ]);
        $session->id = 71;
        $session->exists = true;
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $route = '/_contract/foreign/'.$method.'/projects/{project}/sessions/{session}';
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::post($route, [EstimateGenerationController::class, $method])
            ->middleware(SubstituteBindings::class);
        $this->actingAs($user);

        $response = $this->postJson(str_replace(
            ['{project}', '{session}'],
            ['20', '71'],
            $route,
        ), ['state_version' => 3]);

        $response->assertForbidden()
            ->assertJsonPath('success', false);
    }

    public static function tenantGuardedTransitions(): array
    {
        return [
            'confirm' => ['confirmInput'],
            'retry' => ['retry'],
            'cancel' => ['cancel'],
            'archive' => ['archive'],
        ];
    }

    #[Test]
    public function admin_response_wraps_snapshot_exactly_once(): void
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => 41,
            'project_id' => 17,
            'status' => EstimateGenerationStatus::ReadyToApply,
            'processing_stage' => 'ready',
            'processing_progress' => 100,
            'state_version' => 9,
            'draft_payload' => ['quality_summary' => []],
            'updated_at' => CarbonImmutable::parse('2026-07-11 12:00:00'),
        ]);
        $session->setRelation('documents', collect());

        $snapshot = app(BuildSessionSnapshot::class)->handle(
            session: $session,
            permissions: ['estimate_generation.apply'],
            readinessSummary: ['blockers' => [], 'warnings' => []],
        );
        $response = AdminResponse::success(new EstimateGenerationSessionResource($snapshot));
        $payload = $response->getData(true);

        self::assertTrue($payload['success']);
        self::assertSame(41, $payload['data']['id']);
        self::assertSame('ready_to_apply', $payload['data']['status']);
        self::assertArrayNotHasKey('data', $payload['data']);
        self::assertSame(['apply'], array_column($payload['data']['available_actions'], 'action'));
    }

    #[Test]
    public function real_http_route_returns_standardized_snapshot_and_route_aligned_review_permission(): void
    {
        $project = new Project;
        $project->forceFill(['id' => 17]);
        $session = $this->makeSession(41);
        $session->forceFill(['organization_id' => 9]);
        $session->setRelation('documents', collect());
        $user = new TestPermissionUser(['estimate_generation.view']);
        $user->forceFill(['id' => 3, 'current_organization_id' => 9]);
        $readiness = new CountingEstimatorReadinessService;
        $documents = new CountingDocumentReadinessService;
        $this->app->instance(EstimatorReadinessService::class, $readiness);
        $this->app->instance(DocumentGenerationReadinessService::class, $documents);
        Route::bind('project', static fn (): Project => $project);
        Route::bind('session', static fn (): EstimateGenerationSession => $session);
        Route::get(
            '/_contract/projects/{project}/estimate-generation/sessions/{session}',
            [EstimateGenerationController::class, 'show'],
        )->middleware(SubstituteBindings::class);
        $this->actingAs($user);

        $response = $this->getJson('/_contract/projects/17/estimate-generation/sessions/41');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', 41)
            ->assertJsonPath('data.available_actions.0.action', 'review')
            ->assertJsonPath('data.available_actions.0.method', 'GET')
            ->assertJsonPath('data.readiness_evaluated', true)
            ->assertJsonMissingPath('data.data');
        self::assertSame(1, $readiness->evaluations);
        self::assertSame(1, $documents->evaluations);
    }

    #[Test]
    public function list_collection_does_not_resolve_heavy_readiness_dependencies(): void
    {
        $readiness = new CountingEstimatorReadinessService;
        $this->app->instance(EstimatorReadinessService::class, $readiness);

        $request = Request::create('/api/v1/admin/projects/17/estimate-generation/sessions', 'GET');
        $payload = EstimateGenerationSessionListResource::collection([
            $this->makeSession(41),
            $this->makeSession(42),
        ])->resolve($request);

        self::assertCount(2, $payload);
        self::assertSame([], $payload[0]['documents_summary']);
        self::assertSame([], $payload[0]['blocking_issues']);
        self::assertFalse($payload[0]['readiness_evaluated']);
        self::assertNotContains('apply', array_column($payload[0]['available_actions'], 'action'));
        self::assertSame(0, $readiness->evaluations);
    }

    private function makeSession(int $id): EstimateGenerationSession
    {
        $session = new EstimateGenerationSession;
        $session->forceFill([
            'id' => $id,
            'project_id' => 17,
            'status' => EstimateGenerationStatus::EstimateReviewRequired,
            'processing_stage' => 'review',
            'processing_progress' => 100,
            'state_version' => 9,
            'draft_payload' => ['quality_summary' => []],
            'updated_at' => CarbonImmutable::parse('2026-07-11 12:00:00'),
        ]);

        return $session;
    }
}

final class CountingEstimatorReadinessService extends EstimatorReadinessService
{
    public int $evaluations = 0;

    public function evaluate(EstimateGenerationSession $session): array
    {
        $this->evaluations++;

        return ['blockers' => [], 'warnings' => [], 'metrics' => []];
    }
}

final class CountingDocumentReadinessService extends DocumentGenerationReadinessService
{
    public int $evaluations = 0;

    public function evaluate(EstimateGenerationSession $session): array
    {
        $this->evaluations++;

        return ['summary' => ['total' => 0, 'ready_count' => 0]];
    }
}

final class TestPermissionUser extends User
{
    /** @param list<string> $permissions */
    public function __construct(private array $permissions = [])
    {
        parent::__construct();
    }

    public function hasPermission(string $permission, ?array $context = null): bool
    {
        return in_array($permission, $this->permissions, true);
    }
}
