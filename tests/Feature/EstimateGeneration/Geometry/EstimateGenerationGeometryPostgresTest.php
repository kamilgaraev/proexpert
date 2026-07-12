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
use Illuminate\Database\QueryException;
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

        $fixture = $this->fixture();
        $before = DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->value('content_version');
        DB::beginTransaction();
        try {
            DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->update(['content_version' => 'sha256:'.str_repeat('f', 64)]);
            self::fail('Immutable model update was accepted.');
        } catch (QueryException) {
            DB::rollBack();
        }
        self::assertSame($before, DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->value('content_version'));
        $this->cleanup($fixture);
    }

    #[Test]
    public function same_version_confirmations_have_exactly_one_cas_winner_and_zero_write_loser(): void
    {
        $this->requirePostgres();
        $this->requirePcntl();
        $fixture = $this->fixture();
        Queue::fake();
        $before = $this->counts($fixture);
        $winner = $this->forkConfirmation($fixture, true);
        self::assertSame("locked\n", fgets($winner['socket']));
        $loser = $this->forkConfirmation($fixture, false);
        self::assertSame("attempting\n", fgets($loser['socket']));
        try {
            fwrite($winner['socket'], "release\n");
            self::assertSame("winner\n", fgets($winner['socket']));
            self::assertSame("stale\n", fgets($loser['socket']));
            pcntl_waitpid($winner['pid'], $winnerStatus);
            pcntl_waitpid($loser['pid'], $loserStatus);
            $effects = $this->counts($fixture);
            self::assertSame(2, (int) $fixture['session']->fresh()->state_version);
            self::assertSame($before['models'] + 1, $effects['models']);
            self::assertSame($before['evidence'] + 1, $effects['evidence']);
            self::assertSame($before['outbox'] + 1, $effects['outbox']);
        } finally {
            foreach ([$winner['socket'], $loser['socket']] as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
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
            $url = "/api/v1/admin/projects/{$fixture['project']->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm";
            $payload = $this->payload($fixture);
            $this->postJson($url, $payload)->assertUnauthorized();

            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->andReturnFalse();
            $this->app->instance(AuthorizationService::class, $authorization);
            $this->actingAs($fixture['user'], 'api_admin');
            $this->postJson($url, $payload)->assertForbidden();

            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('can')->andReturnTrue();
            $this->app->instance(AuthorizationService::class, $authorization);
            $response = $this->postJson($url, $payload);

            $response->assertOk()->assertHeader('ETag')->assertHeader('Cache-Control', 'private, no-cache')
                ->assertJsonPath('data.state_version', 2)->assertJsonPath('data.building_model.floors.0.height_m', 3.2)
                ->assertJsonStructure(['data' => ['readiness', 'blocking_clarifications', 'invalidation_summary']]);
            self::assertSame(2, DB::table('estimate_generation_building_models')->where('session_id', $fixture['session']->id)->count());
            self::assertSame($fixture['old_model'], DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->value('content_version'));
            self::assertSame(1, DB::table('estimate_generation_geometry_regeneration_outbox')->where('session_id', $fixture['session']->id)->count());
            self::assertSame($fixture['estimate_sentinel'], (array) DB::table('estimates')->where('id', $fixture['estimate_id'])->first(['name', 'total_amount']));
            self::assertNotNull(DB::table('estimate_generation_evidence')->where('id', $fixture['derived_root_id'])->value('invalidated_at'));
            self::assertNotNull(DB::table('estimate_generation_evidence')->where('id', $fixture['derived_child_id'])->value('invalidated_at'));
            self::assertNull(DB::table('estimate_generation_evidence')->where('id', $fixture['source_evidence_id'])->value('invalidated_at'));
            self::assertSame('superseded', DB::table('estimate_generation_packages')->where('id', $fixture['package_id'])->value('status'));
            self::assertSame('planned', DB::table('estimate_generation_packages')->where('id', $fixture['other_package_id'])->value('status'));
            $itemMetadata = json_decode((string) DB::table('estimate_generation_package_items')->where('id', $fixture['package_item_id'])->value('metadata'), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame('geometry_confirmed', $itemMetadata['invalidation_reason']);
            self::assertSame('invalidated', DB::table('estimate_generation_pipeline_checkpoints')->where('id', $fixture['checkpoint_id'])->value('status'));
            self::assertSame('superseded', DB::table('estimate_generation_processing_units')->where('id', $fixture['unit_id'])->value('status'));
            $userEvidence = DB::table('estimate_generation_evidence')->where('session_id', $fixture['session']->id)
                ->where('source_type', 'user_input')->first();
            self::assertNotNull($userEvidence);
            $provenance = json_decode((string) $userEvidence->value, true, flags: JSON_THROW_ON_ERROR);
            foreach (['previous_state_version', 'new_state_version', 'previous_input_version', 'new_input_version',
                'previous_model_version', 'new_model_version', 'actor_id', 'confirmed_at', 'source_evidence_ids', 'operations'] as $key) {
                self::assertArrayHasKey($key, $provenance);
            }
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
        $foreignOrganization = Organization::factory()->create();
        try {
            $this->postJson("/api/v1/admin/projects/{$foreignProject->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm", $this->payload($fixture))->assertNotFound();
            $fixture['user']->forceFill(['current_organization_id' => $foreignOrganization->id])->save();
            $this->actingAs($fixture['user']->fresh(), 'api_admin');
            $this->postJson($url, $this->payload($fixture))->assertNotFound();
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $this->actingAs($fixture['user']->fresh(), 'api_admin');
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

            $fixture['session']->forceFill(['status' => 'cancelled', 'applied_estimate_id' => null])->save();
            $this->postJson($url, $this->payload($fixture))->assertUnprocessable();
            $fixture['session']->forceFill(['status' => 'applied', 'applied_estimate_id' => 1])->save();
            $this->postJson($url, $this->payload($fixture))->assertUnprocessable();
            self::assertSame($baseline, $this->counts($fixture));
        } finally {
            $foreignProject->delete();
            $foreignOrganization->delete();
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
            public function afterLocksAcquired(): void {}

            public function afterInvalidation(): void
            {
                throw new \RuntimeException('contract-failure');
            }
        });
        $before = $this->counts($fixture);
        $derivativesBefore = $this->derivativeState($fixture);
        try {
            app(ConfirmBuildingGeometry::class)->handle($this->command($fixture));
            self::fail('Injected failure was not propagated.');
        } catch (\RuntimeException $exception) {
            self::assertSame('contract-failure', $exception->getMessage());
            self::assertSame($before, $this->counts($fixture));
            self::assertSame($derivativesBefore, $this->derivativeState($fixture));
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
            DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $first)->update(['status' => 'delivering', 'available_at' => now()->addMinute()]);
            self::assertSame(['claimed' => 0, 'delivered' => 0, 'failed' => 0], $store->recover());
            DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $first)->update(['available_at' => now()->subMinute()]);
            self::assertSame(['claimed' => 1, 'delivered' => 1, 'failed' => 0], $store->recover());
            self::assertSame('delivered', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $first)->value('status'));

            $dispatcher->throws = true;
            $throwing = new GeometryRegenerationIntent((int) $fixture['organization']->id, (int) $fixture['project']->id,
                (int) $fixture['session']->id, 3, $fixture['inputVersion'], 'sha256:'.str_repeat('1', 64), 'sha256:'.str_repeat('2', 64), (string) \Illuminate\Support\Str::uuid());
            $throwingId = $store->append($throwing);
            self::assertFalse($store->deliver($throwingId));
            self::assertSame('failed', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $throwingId)->value('status'));
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function two_process_outbox_claim_enqueues_and_delivers_exactly_once(): void
    {
        $this->requirePostgres();
        $this->requirePcntl();
        $fixture = $this->fixture();
        $store = new EloquentGeometryRegenerationIntentStore(DB::connection(), new GeometryDispatcherContractFake(true));
        $intent = new GeometryRegenerationIntent((int) $fixture['organization']->id, (int) $fixture['project']->id,
            (int) $fixture['session']->id, 2, $fixture['inputVersion'], 'sha256:'.str_repeat('3', 64),
            'sha256:'.str_repeat('4', 64), (string) \Illuminate\Support\Str::uuid());
        $intentId = $store->append($intent);
        $first = $this->forkOutboxDelivery($intentId);
        $second = $this->forkOutboxDelivery($intentId);
        try {
            self::assertSame("ready\n", fgets($first['socket']));
            self::assertSame("ready\n", fgets($second['socket']));
            fwrite($first['socket'], "go\n");
            fwrite($second['socket'], "go\n");
            $results = [trim((string) fgets($first['socket'])), trim((string) fgets($second['socket']))];
            sort($results);
            self::assertSame(['0', '1'], $results);
            pcntl_waitpid($first['pid'], $firstStatus);
            pcntl_waitpid($second['pid'], $secondStatus);
            self::assertSame('delivered', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)->value('status'));
            self::assertSame(1, DB::table('estimate_generation_audit_events')->where('session_id', $fixture['session']->id)
                ->where('event_type', 'geometry_dispatch_contract')->count());
        } finally {
            foreach ([$first['socket'], $second['socket']] as $socket) {
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
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
        $evidenceRepository = new EloquentEvidenceRepository(DB::connection());
        $derivedRoot = $evidenceRepository->insertOrGet(new EvidenceData((int) $organization->id, (int) $project->id,
            (int) $session->id, EvidenceType::Inferred, EvidenceSourceType::Pipeline, 'building-model', $inputVersion,
            ['building_model_id' => $stored->id], ['field_key' => 'derived_geometry', 'field_value' => true], 1, 'contract', 'pipeline:v1'));
        $derivedChild = $evidenceRepository->insertOrGet(new EvidenceData((int) $organization->id, (int) $project->id,
            (int) $session->id, EvidenceType::WorkItem, EvidenceSourceType::Pipeline, 'work-items', $inputVersion,
            ['building_model_id' => $stored->id], ['field_key' => 'work_item', 'field_value' => 'item-1'], 1, 'contract', 'pipeline:v1'));
        DB::table('estimate_generation_evidence_edges')->insert([
            ['organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id,
                'parent_id' => $evidence->id, 'child_id' => $derivedRoot->id, 'relation' => 'supports', 'created_at' => now()],
            ['organization_id' => $organization->id, 'project_id' => $project->id, 'session_id' => $session->id,
                'parent_id' => $derivedRoot->id, 'child_id' => $derivedChild->id, 'relation' => 'supports', 'created_at' => now()],
        ]);
        $packageId = DB::table('estimate_generation_packages')->insertGetId(['session_id' => $session->id, 'input_version' => $inputVersion,
            'key' => 'derived-package', 'title' => 'Derived', 'scope_type' => 'custom', 'status' => 'planned', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $packageItemId = DB::table('estimate_generation_package_items')->insertGetId(['package_id' => $packageId, 'key' => 'derived-item',
            'name' => 'Derived item', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $otherPackageId = DB::table('estimate_generation_packages')->insertGetId(['session_id' => $session->id, 'input_version' => 'sha256:'.str_repeat('e', 64),
            'key' => 'other-package', 'title' => 'Other', 'scope_type' => 'custom', 'status' => 'planned', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $checkpointId = DB::table('estimate_generation_pipeline_checkpoints')->insertGetId(['session_id' => $session->id,
            'organization_id' => $organization->id, 'project_id' => $project->id, 'generation_attempt_id' => (string) \Illuminate\Support\Str::uuid(),
            'base_input_version' => $inputVersion, 'stage' => 'extract_quantities', 'input_version' => $inputVersion,
            'dependency_versions' => '{}', 'output_version' => 'sha256:'.str_repeat('c', 64), 'output_payload' => '{}',
            'artifact_bytes' => 2, 'status' => 'completed', 'metrics' => '{}', 'warnings' => '[]', 'attempt_count' => 1,
            'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $documentId = DB::table('estimate_generation_documents')->insertGetId(['session_id' => $session->id, 'organization_id' => $organization->id,
            'project_id' => $project->id, 'user_id' => $user->id, 'filename' => 'geometry.pdf', 'status' => 'ready',
            'source_version' => $inputVersion, 'created_at' => now(), 'updated_at' => now()]);
        $unitId = DB::table('estimate_generation_processing_units')->insertGetId(['organization_id' => $organization->id, 'project_id' => $project->id,
            'session_id' => $session->id, 'document_id' => $documentId, 'unit_type' => 'pdf_page', 'unit_index' => 0,
            'source_version' => $inputVersion, 'status' => 'pending', 'locator' => '{}', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $estimateId = DB::table('estimates')->insertGetId(['organization_id' => $organization->id, 'project_id' => $project->id,
            'number' => 'GEOMETRY-SENTINEL-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)), 'name' => 'Контрольная смета',
            'estimate_date' => now()->toDateString(), 'total_amount' => 123.45, 'created_at' => now(), 'updated_at' => now()]);

        return compact('organization', 'project', 'user', 'session', 'inputVersion') + [
            'model_id' => $stored->id, 'old_model' => $model->contentVersion(), 'model_version' => $model->contentVersion(),
            'estimate_id' => $estimateId, 'estimate_sentinel' => ['name' => 'Контрольная смета', 'total_amount' => '123.45'],
            'source_evidence_id' => $evidence->id, 'derived_root_id' => $derivedRoot->id, 'derived_child_id' => $derivedChild->id,
            'package_id' => $packageId, 'package_item_id' => $packageItemId, 'other_package_id' => $otherPackageId,
            'checkpoint_id' => $checkpointId, 'document_id' => $documentId, 'unit_id' => $unitId,
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

    private function derivativeState(array $fixture): array
    {
        return [
            'root_invalidated_at' => DB::table('estimate_generation_evidence')->where('id', $fixture['derived_root_id'])->value('invalidated_at'),
            'child_invalidated_at' => DB::table('estimate_generation_evidence')->where('id', $fixture['derived_child_id'])->value('invalidated_at'),
            'source_invalidated_at' => DB::table('estimate_generation_evidence')->where('id', $fixture['source_evidence_id'])->value('invalidated_at'),
            'package_status' => DB::table('estimate_generation_packages')->where('id', $fixture['package_id'])->value('status'),
            'package_metadata' => DB::table('estimate_generation_packages')->where('id', $fixture['package_id'])->value('metadata'),
            'item_metadata' => DB::table('estimate_generation_package_items')->where('id', $fixture['package_item_id'])->value('metadata'),
            'other_package_status' => DB::table('estimate_generation_packages')->where('id', $fixture['other_package_id'])->value('status'),
            'checkpoint_status' => DB::table('estimate_generation_pipeline_checkpoints')->where('id', $fixture['checkpoint_id'])->value('status'),
            'unit_status' => DB::table('estimate_generation_processing_units')->where('id', $fixture['unit_id'])->value('status'),
        ];
    }

    /** @return array{pid:int,socket:mixed} */
    private function forkConfirmation(array $fixture, bool $holdAfterLock): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            DB::disconnect();
            DB::purge();
            if ($holdAfterLock) {
                $this->app->instance(GeometryConfirmationFaultInjector::class, new class($sockets[1]) implements GeometryConfirmationFaultInjector
                {
                    public function __construct(private mixed $socket) {}

                    public function afterLocksAcquired(): void
                    {
                        fwrite($this->socket, "locked\n");
                        fgets($this->socket);
                    }

                    public function afterInvalidation(): void {}
                });
            } else {
                $this->app->instance(GeometryConfirmationFaultInjector::class, new \App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\NoopGeometryConfirmationFaultInjector);
                fwrite($sockets[1], "attempting\n");
            }
            try {
                app(ConfirmBuildingGeometry::class)->handle($this->command($fixture));
                fwrite($sockets[1], $holdAfterLock ? "winner\n" : "unexpected-winner\n");
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                fwrite($sockets[1], "stale\n");
            } catch (\Throwable $exception) {
                fwrite($sockets[1], 'error:'.$exception::class."\n");
            }
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);

        return ['pid' => $pid, 'socket' => $sockets[0]];
    }

    /** @return array{pid:int,socket:mixed} */
    private function forkOutboxDelivery(int $intentId): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            DB::disconnect();
            DB::purge();
            fwrite($sockets[1], "ready\n");
            fgets($sockets[1]);
            $store = new EloquentGeometryRegenerationIntentStore(DB::connection(), new GeometryAuditCountingDispatcher);
            fwrite($sockets[1], $store->deliver($intentId) ? "1\n" : "0\n");
            fclose($sockets[1]);
            exit(0);
        }
        fclose($sockets[1]);

        return ['pid' => $pid, 'socket' => $sockets[0]];
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
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }

    private function requirePcntl(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            self::markTestSkipped('Requires PCNTL and Unix socket support for contention contract.');
        }
    }
}

final class GeometryDispatcherContractFake implements EstimateGenerationRetryDispatcher
{
    public bool $throws = false;

    public function __construct(public bool $acknowledged) {}

    public function dispatchDocuments(array $documentIds): void {}

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): bool
    {
        if ($this->throws) {
            throw new \RuntimeException('dispatcher-failure');
        }

        return $this->acknowledged;
    }
}

final class GeometryAuditCountingDispatcher implements EstimateGenerationRetryDispatcher
{
    public function dispatchDocuments(array $documentIds): void {}

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): bool
    {
        DB::table('estimate_generation_audit_events')->insert([
            'session_id' => $sessionId,
            'event_type' => 'geometry_dispatch_contract',
            'payload' => json_encode(['state_version' => $stateVersion, 'attempt_id' => $attemptId], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return true;
    }
}
