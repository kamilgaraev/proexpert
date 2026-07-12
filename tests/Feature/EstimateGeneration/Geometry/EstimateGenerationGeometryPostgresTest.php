<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\ConfirmBuildingGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\EloquentGeometryRegenerationIntentStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryRegenerationIntent;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class EstimateGenerationGeometryPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function geometry_outbox_has_composite_tenant_fk_idempotency_and_claim_indexes(): void
    {
        $this->requirePostgres();
        $constraints = DB::select("SELECT conname, pg_get_constraintdef(oid) definition FROM pg_constraint WHERE conrelid = 'estimate_generation_geometry_regeneration_outbox'::regclass");
        $definitions = implode("\n", array_map(static fn (object $row): string => $row->conname.' '.$row->definition, $constraints));

        self::assertStringContainsString('eg_geometry_outbox_session_scope_fk', $definitions);
        self::assertStringContainsString('FOREIGN KEY (session_id, organization_id, project_id)', $definitions);
        self::assertStringContainsString('idempotency_key', $definitions);
        self::assertNotEmpty(DB::select("SELECT 1 FROM pg_indexes WHERE tablename = 'estimate_generation_geometry_regeneration_outbox' AND indexdef LIKE '%status%available_at%'"));
    }

    #[Test]
    public function geometry_mutation_tables_enforce_append_only_history_and_transactional_rollback(): void
    {
        $this->requirePostgres();
        $triggers = DB::select("SELECT tgname FROM pg_trigger WHERE tgrelid IN ('estimate_generation_building_models'::regclass, 'estimate_generation_evidence'::regclass) AND NOT tgisinternal");
        $names = array_map(static fn (object $row): string => $row->tgname, $triggers);

        self::assertContains('eg_building_model_immutable_trg', $names);
        self::assertContains('eg_evidence_immutable_trg', $names);

        $before = DB::table('estimate_generation_geometry_regeneration_outbox')->count();
        DB::beginTransaction();
        DB::rollBack();
        self::assertSame($before, DB::table('estimate_generation_geometry_regeneration_outbox')->count());
    }

    #[Test]
    public function same_version_confirmations_have_exactly_one_cas_winner_and_zero_write_loser(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        Queue::fake();
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            fgets($sockets[1]);
            DB::disconnect();
            DB::purge();
            try {
                app(ConfirmBuildingGeometry::class)->handle($this->command($fixture));
                fwrite($sockets[1], "unexpected-winner\n");
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                fwrite($sockets[1], "stale\n");
            }
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);
        try {
            $command = $this->command($fixture);
            app(ConfirmBuildingGeometry::class)->handle($command);
            $afterWinner = $this->counts($fixture);
            fwrite($sockets[0], "go\n");
            self::assertSame("stale\n", fgets($sockets[0]));
            pcntl_waitpid($pid, $status);
            self::assertSame($afterWinner, $this->counts($fixture));
            self::assertSame(2, (int) $fixture['session']->fresh()->state_version);
            self::assertSame(2, $afterWinner['models']);
            self::assertSame(1, $afterWinner['outbox']);
        } finally {
            if (is_resource($sockets[0])) {
                fclose($sockets[0]);
            }
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function real_admin_endpoint_enforces_permission_and_returns_snapshot_etag_without_mutating_old_history(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        Queue::fake();
        try {
            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->andReturnFalse();
            $this->app->instance(AuthorizationService::class, $authorization);
            $this->actingAs($fixture['user'], 'api_admin');
            $url = "/api/v1/admin/projects/{$fixture['project']->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm";
            $payload = $this->payload($fixture);

            $this->postJson($url, $payload)->assertForbidden();

            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->andReturnTrue();
            $this->app->instance(AuthorizationService::class, $authorization);
            $response = $this->postJson($url, $payload);

            $response->assertOk()->assertHeader('ETag')->assertJsonPath('data.state_version', 2)
                ->assertJsonPath('data.building_model.floors.0.height_m', 3.2);
            self::assertSame(2, DB::table('estimate_generation_building_models')->where('session_id', $fixture['session']->id)->count());
            self::assertSame($fixture['old_model'], DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->value('content_version'));
            self::assertSame(1, DB::table('estimate_generation_geometry_regeneration_outbox')->where('session_id', $fixture['session']->id)->count());
            self::assertSame($fixture['estimate_sentinel'], (array) DB::table('estimates')->where('id', $fixture['estimate_id'])->first(['name', 'total_amount']));
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function real_endpoint_rejects_foreign_stale_terminal_noop_unsafe_and_oversize_requests_without_writes(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->actingAs($fixture['user'], 'api_admin');
        $url = "/api/v1/admin/projects/{$fixture['project']->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm";
        $baseline = $this->counts($fixture);
        $foreignProject = Project::factory()->for($fixture['organization'])->create();
        try {
            $this->postJson("/api/v1/admin/projects/{$foreignProject->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm", $this->payload($fixture))->assertNotFound();
            foreach ([
                'state' => ['state_version' => 0],
                'model' => ['model_version' => 'sha256:'.str_repeat('f', 64)],
                'input' => ['input_version' => 'sha256:'.str_repeat('e', 64)],
            ] as $changes) {
                $this->postJson($url, [...$this->payload($fixture), ...$changes])->assertConflict();
                self::assertSame($baseline, $this->counts($fixture));
            }
            $noop = $this->payload($fixture);
            $noop['operations'][0]['value'] = 3;
            $this->postJson($url, $noop)->assertUnprocessable();
            $unsafe = $this->payload($fixture);
            $unsafe['operations'][0]['path'] = '/floors/floor-1/../../metrics';
            $this->postJson($url, $unsafe)->assertUnprocessable();
            $oversize = $this->payload($fixture);
            $oversize['operations'][0]['value'] = str_repeat('x', 262145);
            $this->postJson($url, $oversize)->assertUnprocessable();

            $fixture['session']->forceFill(['status' => 'applied', 'applied_estimate_id' => 1])->save();
            $this->postJson($url, $this->payload($fixture))->assertUnprocessable();
            self::assertSame($baseline, $this->counts($fixture));
        } finally {
            $foreignProject->delete();
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function injected_mid_transaction_failure_rolls_back_evidence_model_invalidation_state_and_outbox(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $this->app->instance(GeometryConfirmationFaultInjector::class, new class implements GeometryConfirmationFaultInjector
        {
            public function afterInvalidation(): void
            {
                throw new \RuntimeException('contract-failure');
            }
        });
        $before = $this->counts($fixture);
        try {
            app(ConfirmBuildingGeometry::class)->handle($this->command($fixture));
            self::fail('Injected failure was not propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('contract-failure', $exception->getMessage());
            self::assertSame($before, $this->counts($fixture));
            self::assertSame(1, (int) $fixture['session']->fresh()->state_version);
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function outbox_requires_acknowledgement_and_recovers_failed_delivery_idempotently(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $dispatcher = new GeometryDispatcherContractFake(false);
        $store = new EloquentGeometryRegenerationIntentStore(DB::connection(), $dispatcher);
        try {
            $intent = new GeometryRegenerationIntent((int) $fixture['organization']->id, (int) $fixture['project']->id,
                (int) $fixture['session']->id, 2, $fixture['inputVersion'], 'sha256:'.str_repeat('c', 64), 'sha256:'.str_repeat('d', 64), (string) \Illuminate\Support\Str::uuid());
            $first = $store->append($intent);
            self::assertSame($first, $store->append($intent));
            self::assertFalse($store->deliver($first));
            self::assertSame('failed', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $first)->value('status'));

            $dispatcher->acknowledged = true;
            DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $first)->update(['available_at' => now()->subMinute()]);
            self::assertSame(['claimed' => 1, 'delivered' => 1, 'failed' => 0], $store->recover());
            self::assertSame('delivered', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $first)->value('status'));
        } finally {
            $this->cleanup($fixture);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'status' => 'input_review_required', 'processing_stage' => 'input_review_required',
            'processing_progress' => 100, 'input_payload' => [], 'state_version' => 1]);
        $inputVersion = 'sha256:'.str_repeat('b', 64);
        $evidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData((int) $organization->id,
            (int) $project->id, (int) $session->id, EvidenceType::Extracted, EvidenceSourceType::Document, 'document:1',
            'sha256:'.str_repeat('a', 64), ['document_id' => 1], ['field_key' => 'height', 'field_value' => 3], 1, 'contract', 'contract:abcdef'));
        $model = new NormalizedBuildingModelData('m', 'confirmed', 0.01,
            [new FloorData('floor-1', 0, 3, [], [], [], [], [$evidence->id], 1, 'confirmed')], [], 'building-model:v1');
        $stored = app(BuildingModelRepository::class)->store(new BuildingModelOperationContext((int) $organization->id,
            (int) $project->id, (int) $session->id, $inputVersion), $model);
        $estimateId = DB::table('estimates')->insertGetId(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'GEOMETRY-SENTINEL-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)), 'name' => 'Контрольная смета',
            'estimate_date' => now()->toDateString(), 'total_amount' => 123.45, 'created_at' => now(), 'updated_at' => now()]);

        return compact('organization', 'project', 'user', 'session', 'inputVersion') + [
            'model_id' => $stored->id, 'old_model' => $model->contentVersion(), 'model_version' => $model->contentVersion(),
            'estimate_id' => $estimateId, 'estimate_sentinel' => ['name' => 'Контрольная смета', 'total_amount' => '123.45'],
        ];
    }

    private function payload(array $fixture): array
    {
        return ['state_version' => 1, 'model_version' => $fixture['model_version'], 'input_version' => $fixture['inputVersion'],
            'operations' => [['op' => 'replace', 'path' => '/floors/floor-1/height_m', 'value' => 3.2]]];
    }

    private function command(array $fixture): GeometryConfirmationCommand
    {
        return new GeometryConfirmationCommand((int) $fixture['organization']->id, (int) $fixture['project']->id,
            (int) $fixture['session']->id, (int) $fixture['user']->id, 1, $fixture['model_version'], $fixture['inputVersion'], null,
            [['op' => 'replace', 'path' => '/floors/floor-1/height_m', 'value' => 3.2]]);
    }

    private function counts(array $fixture): array
    {
        $sessionId = $fixture['session']->id;

        return ['models' => DB::table('estimate_generation_building_models')->where('session_id', $sessionId)->count(),
            'evidence' => DB::table('estimate_generation_evidence')->where('session_id', $sessionId)->count(),
            'outbox' => DB::table('estimate_generation_geometry_regeneration_outbox')->where('session_id', $sessionId)->count()];
    }

    private function cleanup(array $fixture): void
    {
        DB::table('estimates')->where('id', $fixture['estimate_id'])->delete();
        DB::table('estimate_generation_sessions')->where('id', $fixture['session']->id)->delete();
        $fixture['project']->delete();
        $fixture['organization']->delete();
        $fixture['user']->delete();
    }

    private function requirePostgres(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql'
            || ! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }
}

final class GeometryDispatcherContractFake implements EstimateGenerationRetryDispatcher
{
    public function __construct(public bool $acknowledged) {}

    public function dispatchDocuments(array $documentIds): void {}

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): bool
    {
        return $this->acknowledged;
    }
}
