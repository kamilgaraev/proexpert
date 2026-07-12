<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Geometry;

use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\ConfirmBuildingGeometry;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\EloquentGeometryRegenerationIntentStore;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationCommand;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryConfirmationFaultInjector;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryDependencyInvalidator;
use App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\GeometryRegenerationIntent;
use App\BusinessModules\Addons\EstimateGeneration\Application\Sessions\EstimateGenerationRetryDispatcher;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\GeometryConfirmationData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\BusinessModules\Addons\EstimateGeneration\Services\EstimateGenerationPackagePersistenceService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackageInputVersionBackfill;
use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VectorGeometryData;
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
use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;
use Tests\Support\EstimateGeneration\GeometryConfirmationParityCases;
use Tymon\JWTAuth\Facades\JWTAuth;

#[Group('postgres-contract')]
final class EstimateGenerationGeometryPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        $app->singleton(BuildingModelStore::class, static fn ($app): EloquentBuildingModelStore => new EloquentBuildingModelStore($app->make('db')->connection()));

        return $app;
    }

    #[Test]
    #[DataProviderExternal(GeometryConfirmationParityCases::class, 'cases')]
    public function geometry_confirmation_number_contract_has_php_postgres_parity(mixed $realValue, array $indexes, bool $valid): void
    {
        $this->requirePostgres();
        $payload = GeometryConfirmationParityCases::payload($realValue, $indexes);
        $phpAccepted = true;
        try {
            GeometryConfirmationData::fromArray($payload);
        } catch (\InvalidArgumentException) {
            $phpAccepted = false;
        }
        self::assertSame($valid, $phpAccepted);
        try {
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        } catch (\JsonException) {
            self::assertFalse($valid);

            return;
        }
        DB::statement('DROP TABLE IF EXISTS geometry_confirmation_numeric_parity');
        DB::statement('CREATE TEMP TABLE geometry_confirmation_numeric_parity (payload jsonb NOT NULL CHECK (eg_geometry_confirmation_semantic_valid_v1(payload)))');
        try {
            DB::insert('INSERT INTO geometry_confirmation_numeric_parity(payload) VALUES (CAST(? AS jsonb))', [$encoded]);
            self::assertTrue($valid);
        } catch (QueryException) {
            self::assertFalse($valid);
        } finally {
            DB::statement('DROP TABLE geometry_confirmation_numeric_parity');
        }
    }

    #[Test]
    public function session_payload_rollout_resumes_partial_shadow_and_preserves_data_schema_and_nullability(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        try {
            $statementTimeout = DB::selectOne('SHOW statement_timeout')->statement_timeout;
            $lockTimeout = DB::selectOne('SHOW lock_timeout')->lock_timeout;
            $migration = require dirname(__DIR__, 4).'/'.EstimateGenerationContractDatabaseProvisioner::subjectInventory('geometry', dirname(__DIR__, 4))[0];
            $migration->down();
            DB::statement('ALTER TABLE estimate_generation_sessions ADD COLUMN input_payload__jsonb_shadow jsonb');
            try {
                $migration->up();
                self::fail('Ambiguous rollout phase was accepted.');
            } catch (\RuntimeException $exception) {
                self::assertSame('estimate_generation.payload_rollout_ambiguous', $exception->getMessage());
            }
            DB::statement('ALTER TABLE estimate_generation_sessions DROP COLUMN input_payload__jsonb_shadow');
            DB::statement('ALTER TABLE estimate_generation_sessions ADD COLUMN input_payload__jsonb_shadow jsonb, ADD COLUMN analysis_payload__jsonb_shadow jsonb, ADD COLUMN draft_payload__jsonb_shadow jsonb, ADD COLUMN problem_flags__jsonb_shadow jsonb');
            DB::unprepared('CREATE FUNCTION eg_session_payload_dual_write_v1() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.input_payload__jsonb_shadow:=NEW.input_payload::jsonb; NEW.analysis_payload__jsonb_shadow:=NEW.analysis_payload::jsonb; NEW.draft_payload__jsonb_shadow:=NEW.draft_payload::jsonb; NEW.problem_flags__jsonb_shadow:=NEW.problem_flags::jsonb; RETURN NEW; END; $$; CREATE TRIGGER eg_session_payload_dual_write_v1 BEFORE INSERT OR UPDATE ON estimate_generation_sessions FOR EACH ROW EXECUTE FUNCTION eg_session_payload_dual_write_v1();');
            DB::statement('UPDATE estimate_generation_sessions SET input_payload__jsonb_shadow=input_payload::jsonb, analysis_payload__jsonb_shadow=analysis_payload::jsonb, draft_payload__jsonb_shadow=draft_payload::jsonb, problem_flags__jsonb_shadow=problem_flags::jsonb WHERE id=?', [$fixture['session']->id]);
            $writer = new \PDO(
                sprintf('pgsql:host=%s;port=%s;dbname=%s', config('database.connections.pgsql.host'), config('database.connections.pgsql.port'), config('database.connections.pgsql.database')),
                (string) config('database.connections.pgsql.username'),
                (string) config('database.connections.pgsql.password'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
            $update = $writer->prepare('UPDATE estimate_generation_sessions SET input_payload=CAST(:payload AS json) WHERE id=:id');
            $update->execute(['payload' => '{"concurrent":"latest"}', 'id' => $fixture['session']->id]);
            $migration->up();
            self::assertSame(['concurrent' => 'latest'], json_decode((string) DB::table('estimate_generation_sessions')->where('id', $fixture['session']->id)->value('input_payload'), true, flags: JSON_THROW_ON_ERROR));
            $columns = DB::select("SELECT column_name, data_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema='public' AND table_name='estimate_generation_sessions' AND column_name IN ('input_payload','analysis_payload','draft_payload','problem_flags') ORDER BY column_name");
            self::assertCount(4, $columns);
            foreach ($columns as $column) {
                self::assertSame('jsonb', $column->data_type);
                self::assertSame($column->column_name === 'input_payload' ? 'NO' : 'YES', $column->is_nullable);
                self::assertNull($column->column_default);
            }
            DB::statement('ALTER TABLE estimate_generation_sessions ADD COLUMN input_payload__rollout_old json, ADD COLUMN analysis_payload__rollout_old json, ADD COLUMN draft_payload__rollout_old json, ADD COLUMN problem_flags__rollout_old json');
            $writer->beginTransaction();
            $writer->query('SELECT id FROM estimate_generation_sessions LIMIT 1')->fetch();
            try {
                $migration->up();
                self::fail('Cleanup lock timeout was not enforced.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('lock timeout', strtolower($exception->getMessage()));
            } finally {
                $writer->rollBack();
            }
            $migration->up();
            self::assertFalse(DB::getSchemaBuilder()->hasColumn('estimate_generation_sessions', 'input_payload__rollout_old'));
            self::assertSame($statementTimeout, DB::selectOne('SHOW statement_timeout')->statement_timeout);
            self::assertSame($lockTimeout, DB::selectOne('SHOW lock_timeout')->lock_timeout);
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function source_confirmation_endpoint_persists_server_audit_model_evidence_invalidation_and_outbox(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $sourceEvidenceId = $this->attachVectorCapture($fixture);
        Queue::fake();
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->actingAs($fixture['user'], 'api_admin');
        try {
            $preview = app(\App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\AssemblePersistedVectorGeometry::class)
                ->handle($this->sourceCommand($fixture));
            self::assertSame('room-1', $preview->floors[0]->rooms[0]->key);
            $payloadTypes = DB::select("SELECT attname, format_type(atttypid, atttypmod) AS type FROM pg_attribute WHERE attrelid = 'estimate_generation_sessions'::regclass AND attname IN ('input_payload','analysis_payload','draft_payload','problem_flags')");
            self::assertSame(['jsonb'], array_values(array_unique(array_column($payloadTypes, 'type'))));
            $url = "/api/v1/admin/projects/{$fixture['project']->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm";
            $response = $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']))
                ->postJson($url, $this->sourcePayload($fixture));
            self::assertSame(200, $response->status(), json_encode($response->json(), JSON_UNESCAPED_UNICODE));
            $response->assertOk()->assertJsonPath('data.building_model.scale_status', 'confirmed')
                ->assertJsonPath('data.building_model.floors.0.rooms.0.key', 'room-1');
            $newModelId = DB::table('estimate_generation_building_models')->where('session_id', $fixture['session']->id)->max('id');
            self::assertTrue(DB::table('estimate_generation_building_model_evidence')->where('building_model_id', $newModelId)
                ->where('evidence_id', $sourceEvidenceId)->exists());
            $audit = DB::table('estimate_generation_geometry_confirmations')->where('session_id', $fixture['session']->id)->latest('id')->first();
            self::assertNotNull($audit);
            self::assertSame('user_geometry_confirmation', $audit->source_class);
            self::assertSame('user:'.$fixture['user']->id, $audit->reviewer_ref);
            self::assertSame((int) $fixture['user']->id, (int) $audit->actor_id);
            self::assertSame((int) $fixture['model_id'], (int) $audit->previous_building_model_id);
            self::assertSame((int) $newModelId, (int) $audit->confirmed_building_model_id);
            self::assertSame($fixture['inputVersion'], $audit->previous_input_version);
            self::assertSame($fixture['model_version'], $audit->previous_content_version);
            self::assertSame(
                DB::table('estimate_generation_building_models')->where('id', $newModelId)->value('input_version'),
                $audit->confirmed_input_version,
            );
            self::assertSame(
                DB::table('estimate_generation_building_models')->where('id', $newModelId)->value('content_version'),
                $audit->confirmed_content_version,
            );
            self::assertTrue(DB::table('estimate_generation_evidence')->where('id', $audit->evidence_id)
                ->where('session_id', $fixture['session']->id)->exists());
            self::assertNotEmpty($audit->confirmed_at);
            self::assertEquals($this->sourceConfirmation(), json_decode((string) $audit->semantic_payload, true, flags: JSON_THROW_ON_ERROR));
            foreach ([
                ['extra' => true],
                ['privacy' => ['approved' => true]],
                ['scale_evidence' => [['role' => 'unknown']]],
                ['elements' => [['key' => 'room-1', 'type' => 'room', 'boundary_handle' => 'R1', 'extra' => true]]],
                ['elements' => [['key' => 'opening-1', 'type' => 'opening', 'wall_key' => 'missing-wall', 'opening_type' => 'door', 'boundary_handles' => ['W1', 'W2'], 'dimension_handle' => 'D1']]],
                ['elements' => [['key' => 'room-1', 'type' => 'room', 'boundary_handle' => 'H1'], ['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['H1']]]],
                ['elements' => [['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['H1', 'H1']]]],
                ['elements' => [['key' => 'room-1', 'type' => 'room', 'boundary_handle' => str_repeat('x', 513)]]],
                ['elements' => [['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['H1']], ['key' => 'opening-1', 'type' => 'opening', 'wall_key' => 'wall-1', 'opening_type' => 'door', 'boundary_handles' => ['H1', 'H1'], 'dimension_handle' => 'D1']]],
            ] as $mutation) {
                $invalid = [...$this->sourceConfirmation(), ...$mutation];
                $valid = DB::selectOne('SELECT eg_geometry_confirmation_semantic_valid_v1(CAST(? AS jsonb)) AS valid', [json_encode($invalid, JSON_THROW_ON_ERROR)]);
                self::assertFalse((bool) $valid->valid);
            }
            foreach ([
                ['previous_building_model_id' => PHP_INT_MAX],
                ['organization_id' => $fixture['organization']->id + 1000000],
                ['previous_content_version' => 'sha256:'.str_repeat('f', 64)],
            ] as $mutation) {
                $invalidLineage = [...(array) $audit, ...$mutation];
                unset($invalidLineage['id']);
                try {
                    DB::table('estimate_generation_geometry_confirmations')->insert($invalidLineage);
                    self::fail('Invalid geometry confirmation lineage was accepted.');
                } catch (QueryException $exception) {
                    self::assertStringContainsString('geometry_confirmation_lineage_invalid', $exception->getMessage());
                }
            }
            DB::statement('ALTER TABLE estimate_generation_building_models DISABLE TRIGGER eg_building_model_immutable_trg');
            try {
                DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->delete();
                self::fail('Referenced building model was deleted.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('eg_geometry_confirmation_previous_model_fk', $exception->getMessage());
            } finally {
                DB::statement('ALTER TABLE estimate_generation_building_models ENABLE TRIGGER eg_building_model_immutable_trg');
            }
            try {
                DB::table('estimate_generation_geometry_confirmations')->where('id', $audit->id)
                    ->update(['source_class' => 'changed']);
                self::fail('Geometry confirmation audit row was mutable.');
            } catch (QueryException $exception) {
                self::assertStringContainsString('geometry_confirmation_immutable', $exception->getMessage());
            }
            self::assertSame(1, DB::table('estimate_generation_geometry_regeneration_outbox')->where('session_id', $fixture['session']->id)->count());
            self::assertNotNull(DB::table('estimate_generation_evidence')->where('id', $fixture['derived_root_id'])->value('invalidated_at'));
        } finally {
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function source_confirmation_rejects_wrong_version_ambiguous_capture_and_rolls_back_fault(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $this->attachVectorCapture($fixture);
        $authorization = Mockery::mock(AuthorizationService::class);
        $authorization->shouldReceive('can')->andReturnTrue();
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->actingAs($fixture['user'], 'api_admin');
        $url = "/api/v1/admin/projects/{$fixture['project']->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm";
        $before = $this->counts($fixture);
        try {
            $typedInvalid = $this->sourcePayload($fixture);
            $typedInvalid['source_confirmation']['scale_evidence'][0]['real_world_value'] = '4000';
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']))
                ->postJson($url, $typedInvalid)->assertUnprocessable();
            self::assertSame($before, $this->counts($fixture));
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']))
                ->postJson($url, [...$this->sourcePayload($fixture), 'input_version' => 'sha256:'.str_repeat('f', 64)])->assertConflict();
            self::assertSame($before, $this->counts($fixture));
            $this->app->instance(GeometryConfirmationFaultInjector::class, new class implements GeometryConfirmationFaultInjector
            {
                public function afterLocksAcquired(): void {}

                public function afterInvalidation(): void
                {
                    throw new \RuntimeException('source-contract-failure');
                }
            });
            try {
                app(ConfirmBuildingGeometry::class)->handle($this->sourceCommand($fixture));
                self::fail('Source confirmation fault was not propagated.');
            } catch (\RuntimeException $exception) {
                self::assertSame('source-contract-failure', $exception->getMessage());
            }
            self::assertSame($before, $this->counts($fixture));
            self::assertSame(1, (int) $fixture['session']->fresh()->state_version);
        } finally {
            $this->cleanup($fixture);
        }
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
    public function probe_identifier_is_strictly_validated_and_quoted(): void
    {
        self::assertSame('"geometry_dispatch_probe_abcdefghij"', GeometryProbeIdentifier::quote('geometry_dispatch_probe_abcdefghij'));
        $this->expectException(\InvalidArgumentException::class);
        GeometryProbeIdentifier::quote('geometry_dispatch_probe_bad";drop table users');
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
        try {
            DB::beginTransaction();
            try {
                DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->update(['content_version' => 'sha256:'.str_repeat('f', 64)]);
                self::fail('Immutable model update was accepted.');
            } catch (QueryException) {
                DB::rollBack();
            }
            self::assertSame($before, DB::table('estimate_generation_building_models')->where('id', $fixture['model_id'])->value('content_version'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function same_version_confirmations_have_exactly_one_cas_winner_and_zero_write_loser(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        Queue::fake();
        $before = $this->counts($fixture);
        if (! function_exists('pcntl_fork')) {
            try {
                $command = $this->command($fixture);
                $payload = base64_encode(json_encode([
                    $command->organizationId, $command->projectId, $command->sessionId, $command->actorId,
                    $command->expectedStateVersion, $command->expectedModelVersion, $command->expectedInputVersion,
                    $command->scale,
                    array_map(static fn (array $operation): array => [
                        'op' => $operation['op'], 'path' => $operation['path'], 'value' => $operation['value'],
                    ], $command->operations),
                    $command->sourceConfirmation,
                ], JSON_THROW_ON_ERROR));
                $winner = $this->startWorker(['confirm', $payload, '1']);
                usleep(300_000);
                $loser = $this->startWorker(['confirm', $payload, '0']);
                $results = [$this->finishWorker($winner), $this->finishWorker($loser)];
                sort($results);
                self::assertSame(['stale', 'winner'], $results);
                $effects = $this->counts($fixture);
                self::assertSame(2, (int) $fixture['session']->fresh()->state_version);
                self::assertSame($before['models'] + 1, $effects['models']);
                self::assertSame($before['evidence'] + 1, $effects['evidence']);
                self::assertSame($before['outbox'] + 1, $effects['outbox']);
            } finally {
                $this->cleanup($fixture);
            }

            return;
        }
        $this->requirePcntl();
        $winner = null;
        $loser = null;
        try {
            $winner = $this->forkConfirmation($fixture, true);
            self::assertSame("locked\n", $this->readLine($winner['socket']));
            $loser = $this->forkConfirmation($fixture, false);
            self::assertSame("attempting\n", $this->readLine($loser['socket']));
            fwrite($winner['socket'], "release\n");
            self::assertSame("winner\n", $this->readLine($winner['socket']));
            self::assertSame("stale\n", $this->readLine($loser['socket']));
            $this->waitChild($winner['pid']);
            $this->waitChild($loser['pid']);
            $effects = $this->counts($fixture);
            self::assertSame(2, (int) $fixture['session']->fresh()->state_version);
            self::assertSame($before['models'] + 1, $effects['models']);
            self::assertSame($before['evidence'] + 1, $effects['evidence']);
            self::assertSame($before['outbox'] + 1, $effects['outbox']);
        } finally {
            foreach ([$winner, $loser] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $this->terminateChild($child['pid']);
                $socket = $child['socket'];
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
            $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
            $authorization->shouldReceive('can')->andReturnFalse();
            $this->app->instance(AuthorizationService::class, $authorization);
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']));
            $this->postJson($url, $payload)->assertForbidden();

            $authorization = Mockery::mock(AuthorizationService::class);
            $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
            $authorization->shouldReceive('can')->andReturnTrue();
            $this->app->instance(AuthorizationService::class, $authorization);
            $response = $this->postJson($url, $payload);

            $response->assertOk()->assertHeader('ETag')->assertHeader('Cache-Control')
                ->assertJsonPath('data.state_version', 2)->assertJsonPath('data.building_model.floors.0.height_m', 3.2)
                ->assertJsonStructure(['data' => ['readiness', 'blocking_clarifications', 'invalidation_summary']]);
            self::assertEqualsCanonicalizing(['no-cache', 'private'], array_map('trim', explode(',', (string) $response->headers->get('Cache-Control'))));
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
                ->where('source_type', 'user_input')->orderByDesc('id')->first();
            self::assertNotNull($userEvidence);
            self::assertSame('input:'.$fixture['user']->id, $userEvidence->source_ref);
            self::assertSame($response->json('data.input_version'), $userEvidence->source_version);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $userEvidence->fingerprint);
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
        $authorization->shouldReceive('canAccessInterface')->andReturnTrue();
        $authorization->shouldReceive('can')->andReturnTrue();
        $this->app->instance(AuthorizationService::class, $authorization);
        $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']));
        $url = "/api/v1/admin/projects/{$fixture['project']->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm";
        $baseline = $this->counts($fixture);
        $foreignProject = Project::factory()->for($fixture['organization'])->create();
        $foreignOrganization = Organization::factory()->create();
        try {
            $this->postJson("/api/v1/admin/projects/{$foreignProject->id}/estimate-generation/sessions/{$fixture['session']->id}/geometry/confirm", $this->payload($fixture))->assertNotFound();
            $fixture['user']->forceFill(['current_organization_id' => $foreignOrganization->id])->save();
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']->fresh()));
            $this->postJson($url, $this->payload($fixture))->assertForbidden();
            $fixture['user']->forceFill(['current_organization_id' => $fixture['organization']->id])->save();
            $this->withHeader('Authorization', 'Bearer '.JWTAuth::fromUser($fixture['user']->fresh()));
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
            $fixture['session']->forceFill(['status' => 'applied', 'applied_estimate_id' => $fixture['estimate_id']])->save();
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
        $fixture = $this->fixture();
        $store = new EloquentGeometryRegenerationIntentStore(DB::connection(), new GeometryDispatcherContractFake(true));
        $intent = new GeometryRegenerationIntent((int) $fixture['organization']->id, (int) $fixture['project']->id,
            (int) $fixture['session']->id, 2, $fixture['inputVersion'], 'sha256:'.str_repeat('3', 64),
            'sha256:'.str_repeat('4', 64), (string) \Illuminate\Support\Str::uuid());
        $intentId = $store->append($intent);
        $probe = 'geometry_dispatch_probe_'.strtolower(\Illuminate\Support\Str::random(10));
        $quotedProbe = GeometryProbeIdentifier::quote($probe);
        DB::statement("CREATE SEQUENCE {$quotedProbe}");
        if (! function_exists('pcntl_fork')) {
            try {
                $first = $this->startWorker(['outbox', (string) $intentId, $probe]);
                $second = $this->startWorker(['outbox', (string) $intentId, $probe]);
                $results = [$this->finishWorker($first), $this->finishWorker($second)];
                sort($results);
                self::assertSame(['0', '1'], $results);
                self::assertSame('delivered', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)->value('status'));
                $counter = DB::selectOne("SELECT last_value, is_called FROM {$quotedProbe}");
                self::assertTrue((bool) $counter->is_called);
                self::assertSame(1, (int) $counter->last_value);
            } finally {
                DB::statement("DROP SEQUENCE IF EXISTS {$quotedProbe}");
                $this->cleanup($fixture);
            }

            return;
        }
        $this->requirePcntl();
        $first = null;
        $second = null;
        try {
            $first = $this->forkOutboxDelivery($intentId, $probe);
            $second = $this->forkOutboxDelivery($intentId, $probe);
            self::assertSame("ready\n", $this->readLine($first['socket']));
            self::assertSame("ready\n", $this->readLine($second['socket']));
            fwrite($first['socket'], "go\n");
            fwrite($second['socket'], "go\n");
            $results = [trim($this->readLine($first['socket'])), trim($this->readLine($second['socket']))];
            sort($results);
            self::assertSame(['0', '1'], $results);
            $this->waitChild($first['pid']);
            $this->waitChild($second['pid']);
            self::assertSame('delivered', DB::table('estimate_generation_geometry_regeneration_outbox')->where('id', $intentId)->value('status'));
            $counter = DB::selectOne("SELECT last_value, is_called FROM {$quotedProbe}");
            self::assertTrue((bool) $counter->is_called);
            self::assertSame(1, (int) $counter->last_value);
        } finally {
            foreach ([$first, $second] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $this->terminateChild($child['pid']);
                $socket = $child['socket'];
                if (is_resource($socket)) {
                    fclose($socket);
                }
            }
            DB::statement("DROP SEQUENCE IF EXISTS {$quotedProbe}");
            $this->cleanup($fixture);
        }
    }

    #[Test]
    public function package_persistence_backfill_and_exact_invalidation_use_typed_input_version(): void
    {
        $this->requirePostgres();
        $fixture = $this->fixture();
        $version = 'sha256:'.str_repeat('6', 64);
        $otherVersion = 'sha256:'.str_repeat('7', 64);
        try {
            $fixture['session']->forceFill(['input_payload' => ['input_version' => $version]])->save();
            app(EstimateGenerationPackagePersistenceService::class)->syncFromDraft($fixture['session'], ['local_estimates' => [[
                'key' => 'persisted-v2', 'title' => 'Persisted', 'scope_type' => 'custom', 'input_version' => $version, 'sections' => [],
            ]]]);
            $persisted = DB::table('estimate_generation_packages')->where('session_id', $fixture['session']->id)->where('key', 'persisted-v2')->first();
            self::assertSame($version, $persisted->input_version);
            self::assertSame($version, json_decode((string) $persisted->metadata, true, flags: JSON_THROW_ON_ERROR)['input_version']);

            DB::beginTransaction();
            $historicalId = $this->insertPackage($fixture, 'historical-v2', null, ['generated_from' => 'estimate_generation_v2', 'input_version' => $version]);
            $legacyId = $this->insertPackage($fixture, 'legacy', null, ['generated_from' => 'legacy', 'input_version' => $version]);
            $invalidId = $this->insertPackage($fixture, 'invalid-v2', null, ['generated_from' => 'estimate_generation_v2', 'input_version' => 'invalid']);
            $otherId = $this->insertPackage($fixture, 'other-version', $otherVersion, ['generated_from' => 'estimate_generation_v2', 'input_version' => $otherVersion]);
            $historicalItem = DB::table('estimate_generation_package_items')->insertGetId(['package_id' => $historicalId, 'key' => 'historical-item',
                'name' => 'Historical', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            $otherItem = DB::table('estimate_generation_package_items')->insertGetId(['package_id' => $otherId, 'key' => 'other-item',
                'name' => 'Other', 'metadata' => '{}', 'created_at' => now(), 'updated_at' => now()]);
            (new PackageInputVersionBackfill)->run(DB::connection());
            self::assertSame($version, DB::table('estimate_generation_packages')->where('id', $historicalId)->value('input_version'));
            self::assertNull(DB::table('estimate_generation_packages')->where('id', $legacyId)->value('input_version'));
            self::assertNull(DB::table('estimate_generation_packages')->where('id', $invalidId)->value('input_version'));

            app(GeometryDependencyInvalidator::class)->invalidate((int) $fixture['session']->id, $version, 2);
            self::assertSame('superseded', DB::table('estimate_generation_packages')->where('id', $historicalId)->value('status'));
            self::assertSame('planned', DB::table('estimate_generation_packages')->where('id', $otherId)->value('status'));
            self::assertStringContainsString('geometry_confirmed', (string) DB::table('estimate_generation_package_items')->where('id', $historicalItem)->value('metadata'));
            self::assertSame('{}', (string) DB::table('estimate_generation_package_items')->where('id', $otherItem)->value('metadata'));
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanup($fixture);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->make();
        $project->saveQuietly();
        $user = User::factory()->create(['current_organization_id' => $organization->id]);
        DB::table('organization_user')->insert(['organization_id' => $organization->id, 'user_id' => $user->id,
            'is_owner' => true, 'is_active' => true, 'project_access_mode' => 'all_projects',
            'created_at' => now(), 'updated_at' => now()]);
        $session = EstimateGenerationSession::query()->create(['organization_id' => $organization->id, 'project_id' => $project->id,
            'user_id' => $user->id, 'status' => 'input_review_required', 'processing_stage' => 'input_review_required',
            'processing_progress' => 100, 'input_payload' => [], 'state_version' => 1]);
        $inputVersion = 'sha256:'.str_repeat('b', 64);
        $evidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData((int) $organization->id,
            (int) $project->id, (int) $session->id, EvidenceType::Extracted, EvidenceSourceType::Document, 'document:1',
            'sha256:'.str_repeat('a', 64), ['document_id' => 1], ['field_key' => 'floor_height', 'field_value' => 3], 1, 'contract', 'contract:abcdef'));
        $model = new NormalizedBuildingModelData('m', 'confirmed', 0.01,
            [new FloorData('floor-1', 0, 3, [], [], [], [], [$evidence->id], 1, 'confirmed')], [], 'building-model:v1');
        $stored = app(BuildingModelRepository::class)->store(new BuildingModelOperationContext((int) $organization->id,
            (int) $project->id, (int) $session->id, $inputVersion), $model);
        $evidenceRepository = new EloquentEvidenceRepository(DB::connection());
        $derivedRoot = $evidenceRepository->insertOrGet(new EvidenceData((int) $organization->id, (int) $project->id,
            (int) $session->id, EvidenceType::Inferred, EvidenceSourceType::Pipeline, 'pipeline:quantity_takeoff', $inputVersion,
            ['inference_key' => 'inference:'.$stored->id], ['result_code' => 'element_type:room'], 1, 'contract', 'pipeline:v1'));
        $derivedChild = $evidenceRepository->insertOrGet(new EvidenceData((int) $organization->id, (int) $project->id,
            (int) $session->id, EvidenceType::WorkItem, EvidenceSourceType::Pipeline, 'pipeline:decompose', $inputVersion,
            ['item_key' => 'item:'.$stored->id], ['work_code' => 'work_type:'.$stored->id, 'quantity' => '1', 'unit' => 'm'], 1, 'contract', 'pipeline:v1'));
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
            'session_id' => $session->id, 'document_id' => $documentId, 'unit_type' => 'pdf_page', 'unit_index' => 1,
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

    private function attachVectorCapture(array $fixture): int
    {
        $payload = $this->vectorPayload();
        DB::table('estimate_generation_processing_units')->where('id', $fixture['unit_id'])->update([
            'status' => 'completed', 'output_version' => $fixture['inputVersion'], 'output_count' => 1,
            'completed_at' => now(), 'metadata' => json_encode(['vector_geometry' => $payload], JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
        $evidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(
            (int) $fixture['organization']->id, (int) $fixture['project']->id, (int) $fixture['session']->id,
            EvidenceType::Extracted, EvidenceSourceType::Document, 'document:'.$fixture['document_id'], $fixture['inputVersion'],
            ['document_id' => $fixture['document_id']], ['field_key' => 'area', 'field_value' => 12], 1,
            'pdf_geometry', 'model:v1',
        ));

        return $evidence->id;
    }

    private function sourcePayload(array $fixture): array
    {
        return ['state_version' => 1, 'model_version' => $fixture['model_version'], 'input_version' => $fixture['inputVersion'],
            'operations' => [], 'source_confirmation' => $this->sourceConfirmation()];
    }

    private function sourceCommand(array $fixture): GeometryConfirmationCommand
    {
        return new GeometryConfirmationCommand((int) $fixture['organization']->id, (int) $fixture['project']->id,
            (int) $fixture['session']->id, (int) $fixture['user']->id, 1, $fixture['model_version'],
            $fixture['inputVersion'], null, [], $this->sourceConfirmation());
    }

    private function sourceConfirmation(): array
    {
        $vector = VectorGeometryData::fromArray($this->vectorPayload());

        return ['schema_version' => 1, 'source_fingerprint' => $vector->sourceFingerprint,
            'geometry_payload_sha256' => $vector->payloadSha256(),
            'scale_evidence' => [['role' => 'measured_segment', 'entity_handle' => 'W1', 'point_indexes' => [0, 1],
                'real_world_value' => 4000, 'unit' => 'mm']],
            'elements' => [['key' => 'room-1', 'type' => 'room', 'boundary_handle' => 'R1'],
                ['key' => 'wall-1', 'type' => 'wall', 'segment_handles' => ['W1']]]];
    }

    private function vectorPayload(): array
    {
        return ['schema_version' => 1, 'runtime_version' => 'cad-geometry:v1;ezdxf:1.4.4',
            'source_fingerprint' => 'sha256:'.str_repeat('9', 64), 'source_unit' => 'mm', 'unit_status' => 'confirmed',
            'bounds' => [0, 0, 4000, 3000], 'layers' => [['name' => 'A', 'visible' => true]], 'blocks' => [],
            'entities' => [['handle' => 'R1', 'type' => 'lwpolyline', 'layer' => 'A',
                'points' => [[0, 0], [4000, 0], [4000, 3000], [0, 3000]], 'closed' => true],
                ['handle' => 'W1', 'type' => 'line', 'layer' => 'A', 'points' => [[0, 0], [4000, 0]]]],
            'texts' => [], 'dimensions' => [], 'pages' => [], 'scale_candidates' => [], 'warnings' => []];
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

    private function insertPackage(array $fixture, string $key, ?string $inputVersion, array $metadata): int
    {
        return (int) DB::table('estimate_generation_packages')->insertGetId([
            'session_id' => $fixture['session']->id,
            'input_version' => $inputVersion,
            'key' => $key,
            'title' => $key,
            'scope_type' => 'custom',
            'status' => 'planned',
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
                        stream_set_timeout($this->socket, 10);
                        fwrite($this->socket, "locked\n");
                        if (fgets($this->socket) !== "release\n") {
                            throw new \RuntimeException('winner_barrier_timeout');
                        }
                    }

                    public function afterInvalidation(): void {}
                });
            } else {
                $this->app->instance(GeometryConfirmationFaultInjector::class, new \App\BusinessModules\Addons\EstimateGeneration\Application\Geometry\NoopGeometryConfirmationFaultInjector);
                fwrite($sockets[1], "attempting\n");
            }
            $exit = 0;
            try {
                app(ConfirmBuildingGeometry::class)->handle($this->command($fixture));
                fwrite($sockets[1], $holdAfterLock ? "winner\n" : "unexpected-winner\n");
                $exit = $holdAfterLock ? 0 : 2;
            } catch (\App\BusinessModules\Addons\EstimateGeneration\Domain\Workflow\StaleEstimateGenerationState) {
                fwrite($sockets[1], "stale\n");
            } catch (\Throwable $exception) {
                fwrite($sockets[1], 'error:'.$exception::class."\n");
                $exit = 1;
            }
            fclose($sockets[1]);
            exit($exit);
        }
        fclose($sockets[1]);

        return ['pid' => $pid, 'socket' => $sockets[0]];
    }

    /** @return array{pid:int,socket:mixed} */
    private function forkOutboxDelivery(int $intentId, string $probe): array
    {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($sockets);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($sockets[0]);
            DB::disconnect();
            DB::purge();
            stream_set_timeout($sockets[1], 10);
            fwrite($sockets[1], "ready\n");
            if (fgets($sockets[1]) !== "go\n") {
                fwrite($sockets[1], "error:barrier_timeout\n");
                fclose($sockets[1]);
                exit(1);
            }
            try {
                $store = new EloquentGeometryRegenerationIntentStore(DB::connection(), new GeometryProbeDispatcher($probe));
                fwrite($sockets[1], $store->deliver($intentId) ? "1\n" : "0\n");
                $exit = 0;
            } catch (\Throwable $exception) {
                fwrite($sockets[1], 'error:'.$exception::class."\n");
                $exit = 1;
            }
            fclose($sockets[1]);
            exit($exit);
        }
        fclose($sockets[1]);

        return ['pid' => $pid, 'socket' => $sockets[0]];
    }

    private function readLine(mixed $socket, int $seconds = 15): string
    {
        stream_set_timeout($socket, $seconds);
        $line = fgets($socket);
        $metadata = stream_get_meta_data($socket);
        self::assertFalse((bool) ($metadata['timed_out'] ?? false), 'Child process socket timed out.');
        self::assertIsString($line, 'Child process closed its socket without a result.');

        return $line;
    }

    /** @return array{process:resource,pipes:array<int,resource>} */
    private function startWorker(array $arguments): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3).'/Support/GeometryContractWorker.php', ...$arguments],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 4),
            null,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }

    private function finishWorker(array $worker): string
    {
        $stdout = trim((string) stream_get_contents($worker['pipes'][1]));
        $stderr = trim((string) stream_get_contents($worker['pipes'][2]));
        fclose($worker['pipes'][1]);
        fclose($worker['pipes'][2]);
        $exit = proc_close($worker['process']);
        self::assertSame(0, $exit, $stderr);

        return $stdout;
    }

    private function waitChild(int $pid, int $seconds = 15): void
    {
        $deadline = microtime(true) + $seconds;
        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid) {
                self::assertTrue(pcntl_wifexited($status));
                self::assertSame(0, pcntl_wexitstatus($status));

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        $this->terminateChild($pid);
        self::fail("Child process {$pid} did not exit before deadline.");
    }

    private function terminateChild(int $pid): void
    {
        $observed = pcntl_waitpid($pid, $status, WNOHANG);
        if ($observed === $pid || $observed === -1) {
            return;
        }
        @posix_kill($pid, SIGTERM);
        if ($this->reapChildWithin($pid, 2.0)) {
            return;
        }
        @posix_kill($pid, SIGKILL);
        if (! $this->reapChildWithin($pid, 2.0)) {
            self::fail("Child process {$pid} could not be reaped after TERM/KILL deadlines.");
        }
    }

    private function reapChildWithin(int $pid, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        do {
            $result = pcntl_waitpid($pid, $status, WNOHANG);
            if ($result === $pid || $result === -1) {
                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }

    private function cleanup(array $fixture): void
    {
        if (DB::getSchemaBuilder()->hasTable('estimate_generation_geometry_confirmations')) {
            DB::statement('ALTER TABLE estimate_generation_geometry_confirmations DISABLE TRIGGER eg_geometry_confirmation_guard_trg');
            DB::table('estimate_generation_geometry_confirmations')->where('session_id', $fixture['session']->id)->delete();
            DB::statement('ALTER TABLE estimate_generation_geometry_confirmations ENABLE TRIGGER eg_geometry_confirmation_guard_trg');
        }
        DB::table('estimates')->where('id', $fixture['estimate_id'])->delete();
        DB::table('estimate_generation_sessions')->where('id', $fixture['session']->id)->delete();
        $fixture['project']->deleteQuietly();
        $fixture['organization']->deleteQuietly();
        $fixture['user']->deleteQuietly();
    }

    private function requirePostgres(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1' || DB::getDriverName() !== 'pgsql') {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }

    private function requirePcntl(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair') || ! function_exists('posix_kill')) {
            self::markTestSkipped('Requires PCNTL, POSIX signals and Unix socket support for contention contract.');
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

final class GeometryProbeDispatcher implements EstimateGenerationRetryDispatcher
{
    public function __construct(private string $probe) {}

    public function dispatchDocuments(array $documentIds): void {}

    public function dispatchGeneration(int $sessionId, int $stateVersion, string $attemptId): bool
    {
        DB::selectOne('SELECT nextval(CAST(? AS regclass))', [GeometryProbeIdentifier::validate($this->probe)]);

        return true;
    }
}

final class GeometryProbeIdentifier
{
    public static function validate(string $identifier): string
    {
        if (preg_match('/^geometry_dispatch_probe_[a-z0-9]{10}$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('Invalid geometry probe identifier.');
        }

        return $identifier;
    }

    public static function quote(string $identifier): string
    {
        return '"'.self::validate($identifier).'"';
    }
}
