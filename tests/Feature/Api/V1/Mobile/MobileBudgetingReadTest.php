<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1\Mobile;

use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlRow;
use App\BusinessModules\Features\Budgeting\Reporting\ProjectControl\Models\ProjectControlSnapshot;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Project;
use App\Models\User;
use App\Modules\Core\AccessController;
use App\Services\Mobile\MobileBudgetingService;
use App\Services\Mobile\MobileProjectAccessResolver;
use App\Services\Project\UserProjectAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

final class MobileBudgetingReadTest extends TestCase
{
    public function refreshDatabase(): void {}

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->boolean('is_archived')->default(false);
            $table->boolean('is_onboarding_demo')->default(false);
            $table->softDeletes();
        });
        Schema::create('project_control_snapshots', function (Blueprint $table): void {
            $table->string('id', 26)->primary();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('baseline_version_id');
            $table->date('status_date');
            $table->string('wip_version', 128);
            $table->string('progress_watermark', 128);
            $table->string('actual_cost_watermark', 128);
            $table->string('formula_version', 64);
            $table->char('definition_hash', 64);
            $table->char('query_hash', 64);
            $table->char('source_hash', 64);
            $table->timestampTz('generated_at');
            $table->timestampTz('stale_at')->nullable();
            $table->jsonb('watermarks');
            $table->jsonb('totals');
            $table->jsonb('source_refs');
            $table->jsonb('row_schema');
            $table->unsignedInteger('row_count');
            $table->timestampsTz();
        });
        Schema::create('project_control_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('snapshot_id', 26);
            $table->string('row_key', 160);
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('task_id');
            $table->string('wbs_code')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('bac_minor');
            $table->bigInteger('pv_minor');
            $table->bigInteger('ev_minor');
            $table->bigInteger('ac_minor');
            $table->bigInteger('approved_etc_minor')->nullable();
            $table->bigInteger('sv_minor');
            $table->bigInteger('cv_minor');
            $table->decimal('spi', 20, 8)->nullable();
            $table->decimal('cpi', 20, 8)->nullable();
            $table->bigInteger('eac_minor')->nullable();
            $table->jsonb('payload');
            $table->jsonb('source_refs');
        });
        DB::table('projects')->insert([
            'id' => 10,
            'organization_id' => 1,
            'name' => 'Project A',
            'is_archived' => false,
            'is_onboarding_demo' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('project_control_rows');
        Schema::dropIfExists('project_control_snapshots');
        Schema::dropIfExists('projects');

        parent::tearDown();
    }

    public function test_summary_and_execution_cards_only_expose_standard_evm_fields(): void
    {
        $organizationId = 1;
        $projectId = 10;
        $actor = new User;
        $actor->setAttribute('id', 7);
        $actor->setAttribute('current_organization_id', $organizationId);

        $snapshot = ProjectControlSnapshot::query()->create([
            'id' => '01J00000000000000000000001',
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'baseline_version_id' => 1,
            'status_date' => '2026-09-20',
            'wip_version' => 'wip-1',
            'progress_watermark' => 'progress-1',
            'actual_cost_watermark' => 'actual-1',
            'formula_version' => 'project_control_core.v1',
            'definition_hash' => str_repeat('a', 64),
            'query_hash' => str_repeat('b', 64),
            'source_hash' => str_repeat('c', 64),
            'generated_at' => '2026-09-20T10:00:00Z',
            'stale_at' => '2026-09-21T10:00:00Z',
            'watermarks' => [],
            'totals' => ['currencies' => ['RUB' => [
                'bac_minor' => 100000,
                'pv_minor' => 40000,
                'ev_minor' => 35000,
                'sv_minor' => -5000,
                'spi' => '0.87500000',
                'ac_minor' => 30000,
                'eac_minor' => 90000,
            ]]],
            'source_refs' => [],
            'row_schema' => [],
            'row_count' => 2,
        ]);

        foreach ([101, 102] as $taskId) {
            ProjectControlRow::query()->create([
                'organization_id' => $organizationId,
                'snapshot_id' => $snapshot->id,
                'row_key' => 'row-'.$taskId,
                'project_id' => $projectId,
                'task_id' => $taskId,
                'wbs_code' => '1.'.$taskId,
                'currency' => 'RUB',
                'bac_minor' => 50000,
                'pv_minor' => 20000,
                'ev_minor' => 17500,
                'ac_minor' => 15000,
                'approved_etc_minor' => 30000,
                'sv_minor' => -2500,
                'cv_minor' => 2500,
                'spi' => '0.87500000',
                'cpi' => '1.16666667',
                'eac_minor' => 45000,
                'payload' => [],
                'source_refs' => [],
            ]);
        }

        $projectAccessQuery = Mockery::mock(UserProjectAccessService::class);
        $projectAccessQuery->shouldReceive('queryAccessibleProjects')->twice()->with($actor, $organizationId)
            ->andReturnUsing(static fn (User $user, int $id) => Project::query()->where('organization_id', $id));
        $projectAccess = new MobileProjectAccessResolver($projectAccessQuery);
        $moduleAccess = Mockery::mock(AccessController::class);
        $moduleAccess->shouldReceive('hasModuleAccess')->twice()->with($organizationId, 'budgeting')->andReturnTrue();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->twice()->with($actor, 'reports.project_control.view', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])->andReturnTrue();

        $service = new MobileBudgetingService($moduleAccess, $authorization, $projectAccess);
        $summary = $service->summary($actor, $organizationId, $projectId);
        $cards = $service->executionCards($actor, $organizationId, $projectId, 1, 1);

        self::assertSame('Project A', $summary['project']['name']);
        self::assertSame(['currency', 'bac_minor', 'pv_minor', 'ev_minor', 'sv_minor', 'spi'], array_keys($summary['totals_by_currency'][0]));
        self::assertArrayNotHasKey('ac_minor', $summary['totals_by_currency'][0]);
        self::assertSame(2, $cards->total());
        self::assertSame(1, $cards->perPage());
        self::assertArrayNotHasKey('ac_minor', $cards->items()[0]);
        self::assertArrayNotHasKey('eac_minor', $cards->items()[0]);
    }

    public function test_project_scope_permission_is_required_inside_the_service(): void
    {
        $organizationId = 1;
        $projectId = 10;
        $actor = new User;
        $actor->setAttribute('id', 7);
        $actor->setAttribute('current_organization_id', $organizationId);
        $projectAccessQuery = Mockery::mock(UserProjectAccessService::class);
        $projectAccessQuery->shouldReceive('queryAccessibleProjects')->once()->with($actor, $organizationId)
            ->andReturnUsing(static fn (User $user, int $id) => Project::query()->where('organization_id', $id));
        $projectAccess = new MobileProjectAccessResolver($projectAccessQuery);
        $moduleAccess = Mockery::mock(AccessController::class);
        $moduleAccess->shouldReceive('hasModuleAccess')->once()->with($organizationId, 'budgeting')->andReturnTrue();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->once()->with($actor, 'reports.project_control.view', [
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'strict_project_scope' => true,
        ])->andReturnFalse();

        $service = new MobileBudgetingService($moduleAccess, $authorization, $projectAccess);

        $this->expectException(AuthorizationException::class);
        $service->summary($actor, $organizationId, $projectId);
    }

    public function test_mobile_routes_are_registered_under_the_budgeting_project_scope(): void
    {
        $uris = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): string => $route->uri())
            ->all();

        self::assertContains('api/v1/mobile/budgeting/projects/{project}/summary', $uris);
        self::assertContains('api/v1/mobile/budgeting/projects/{project}/execution-cards', $uris);
    }
}
