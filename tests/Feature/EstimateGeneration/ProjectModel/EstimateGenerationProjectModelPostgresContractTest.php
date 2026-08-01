<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class EstimateGenerationProjectModelPostgresContractTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function project_model_schema_accepts_each_supported_entity_and_rejects_invalid_evidence_bindings(): void
    {
        $this->requireEnvironment();
        $this->assertSchemaObjects();

        DB::beginTransaction();
        try {
            $fixture = $this->fixture();
            $entities = [];
            foreach ($this->validPayloads() as $kind => $payload) {
                $entities[$kind] = $this->insertEntity($fixture, $kind, $payload);
            }
            self::assertCount(7, $entities);

            foreach ($this->invalidPayloads() as $kind => $payload) {
                $this->assertRejected(fn () => $this->insertEntity($fixture, $kind, $payload));
            }

            $roomAssertionId = $this->insertCadAssertion($fixture, $entities['room'], 'assertion:room:height');
            $wallAssertionId = $this->insertCadAssertion($fixture, $entities['wall'], 'assertion:wall:height');
            $this->insertEvidenceBinding($fixture, $entities['room'], $roomAssertionId);
            DB::table('estimate_generation_evidence')->where('id', $fixture['evidence_id'])->update([
                'invalidated_at' => now(),
                'invalidation_version' => 1,
                'updated_at' => now(),
            ]);

            $this->assertRejected(fn () => $this->insertEvidenceBinding($fixture, $entities['wall'], $wallAssertionId));
            $this->assertRejected(fn () => DB::table('estimate_generation_project_model_evidence_bindings')->insert([
                ...$this->evidenceBindingRow($fixture, $entities['opening'], $roomAssertionId),
                'organization_id' => $fixture['organization_id'] + 1000000,
            ]));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function project_model_migrations_roll_back_and_reapply_in_the_disposable_contract_database(): void
    {
        $this->requireEnvironment();
        $migration150 = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000150_add_project_model_projection_scope_indexes.php';
        $migration200 = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000200_create_estimate_generation_project_model_tables.php';
        $migration225 = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000225_add_project_model_correction_scope_unique.php';
        $migration250 = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000250_bind_project_model_evidence_to_exact_candidate.php';
        $migration275 = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000275_bind_project_model_evidence_to_canonical_locator.php';

        $migration275->down();
        $migration250->down();
        $migration225->down();
        $migration200->down();
        $migration150->down();

        foreach ([
            'estimate_generation_project_model_entities',
            'estimate_generation_project_model_assertions',
            'estimate_generation_project_model_relations',
            'estimate_generation_project_model_corrections',
            'estimate_generation_project_model_evidence_bindings',
        ] as $table) {
            self::assertNull(DB::selectOne("SELECT to_regclass('public.{$table}') AS object_name")->object_name, $table);
        }
        foreach (['eg_building_models_projection_scope_uq', 'eg_building_model_evidence_projection_scope_uq'] as $index) {
            self::assertNull(DB::selectOne('SELECT to_regclass(?) AS object_name', ["public.{$index}"])->object_name, $index);
        }

        $migration150->up();
        $migration200->up();
        $migration225->up();
        $migration250->up();
        $migration275->up();
        $this->assertSchemaObjects();
    }

    private function assertSchemaObjects(): void
    {
        foreach ([
            'eg_building_models_projection_scope_uq',
            'eg_building_model_evidence_projection_scope_uq',
            'eg_project_model_entities_model_key_uq',
            'eg_project_model_entities_scope_uq',
            'eg_project_model_entities_kind_idx',
            'eg_project_model_assertions_model_key_uq',
            'eg_project_model_assertions_scope_uq',
            'eg_project_model_assertions_entity_idx',
            'eg_project_model_relations_model_key_uq',
            'eg_project_model_relations_from_idx',
            'eg_project_model_relations_to_idx',
            'eg_project_model_corrections_model_key_uq',
            'eg_project_model_corrections_assertion_idx',
            'eg_project_model_evidence_candidate_binding_uq',
            'eg_project_model_evidence_entity_idx',
            'eg_project_model_evidence_assertion_idx',
            'eg_project_model_evidence_correction_idx',
            'eg_project_model_corrections_scope_uq',
        ] as $index) {
            $catalog = DB::selectOne('SELECT i.indisvalid, i.indisready FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?', [$index]);
            self::assertNotNull($catalog, $index);
            self::assertTrue((bool) $catalog->indisvalid, $index);
            self::assertTrue((bool) $catalog->indisready, $index);
        }

        foreach ([
            'eg_project_model_entities_model_scope_fk',
            'eg_project_model_assertions_model_scope_fk',
            'eg_project_model_assertions_entity_scope_fk',
            'eg_project_model_relations_model_scope_fk',
            'eg_project_model_relations_from_scope_fk',
            'eg_project_model_relations_to_scope_fk',
            'eg_project_model_corrections_model_scope_fk',
            'eg_project_model_corrections_assertion_scope_fk',
            'eg_project_model_corrections_actor_fk',
            'eg_project_model_evidence_model_scope_fk',
            'eg_project_model_evidence_entity_scope_fk',
            'eg_project_model_evidence_provenance_fk',
            'eg_project_model_evidence_assertion_scope_fk',
            'eg_project_model_evidence_correction_scope_fk',
            'eg_project_model_evidence_candidate_subject_ck',
            'eg_project_model_evidence_candidate_source_ck',
            'eg_project_model_evidence_candidate_fingerprint_ck',
            'eg_project_model_evidence_candidate_version_ck',
            'eg_project_model_evidence_candidate_locator_ck',
        ] as $constraint) {
            self::assertTrue((bool) DB::table('pg_constraint')->where('conname', $constraint)->value('convalidated'), $constraint);
        }

        foreach ([
            'eg_project_model_entity_payload_guard_trg',
            'eg_project_model_evidence_binding_guard_trg',
            'eg_project_model_evidence_locator_guard_trg',
            'eg_project_model_entity_append_trg',
            'eg_project_model_assertion_append_trg',
            'eg_project_model_relation_append_trg',
            'eg_project_model_correction_append_trg',
            'eg_project_model_evidence_binding_append_trg',
        ] as $trigger) {
            self::assertSame('O', DB::table('pg_trigger')->where('tgname', $trigger)->value('tgenabled'), $trigger);
        }
    }

    private function fixture(): array
    {
        $organization = Organization::factory()->create();
        $project = Project::factory()->for($organization)->create();
        $user = User::factory()->create();
        $session = EstimateGenerationSession::query()->create([
            'organization_id' => $organization->id,
            'project_id' => $project->id,
            'user_id' => $user->id,
            'status' => 'draft',
            'processing_stage' => 'draft',
            'processing_progress' => 0,
            'input_payload' => [],
            'state_version' => 0,
        ]);
        $evidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            EvidenceType::Extracted,
            EvidenceSourceType::Document,
            'document:1',
            'sha256:'.str_repeat('a', 64),
            ['document_id' => 1],
            ['field_key' => 'floor_height', 'field_value' => 2.8, 'unit' => 'm'],
            1,
            'contract',
            'contract:abcdef',
        ));
        $context = new BuildingModelOperationContext((int) $organization->id, (int) $project->id, (int) $session->id, 'sha256:'.str_repeat('b', 64));
        $model = (new BuildingModelRepository(
            new EloquentBuildingModelStore(DB::connection()),
            new EloquentEvidenceRepository(DB::connection()),
        ))->store($context, new NormalizedBuildingModelData('m', 'confirmed', 0.01, [
            new FloorData('floor-1', 0, 2.8, [], [], [], [], [$evidence->id], 1, 'confirmed'),
        ], [], 'building-model:v1'));

        return [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'session_id' => (int) $session->id,
            'building_model_id' => $model->id,
            'source_version' => $model->contentVersion,
            'evidence_id' => $evidence->id,
            'evidence_source_version' => 'sha256:'.str_repeat('a', 64),
        ];
    }

    private function validPayloads(): array
    {
        return [
            'room' => ['kind' => 'room', 'key' => 'room:1', 'polygon' => [[0, 0], [1, 0], [1, 1]]],
            'wall' => ['kind' => 'wall', 'key' => 'wall:1', 'start' => [0, 0], 'end' => [1, 0]],
            'opening' => ['kind' => 'opening', 'key' => 'opening:1', 'wall_key' => 'wall:1', 'type' => 'door', 'width_m' => 1, 'height_m' => 2],
            'dimension' => ['kind' => 'dimension', 'key' => 'dimension:1', 'value' => 1, 'unit' => 'm'],
            'table' => ['kind' => 'table', 'key' => 'table:1', 'columns' => ['name'], 'rows' => [['name' => 'room']]],
            'structural_element' => ['kind' => 'structural_element', 'key' => 'structural_element:1', 'type' => 'beam', 'location' => [0, 0]],
            'quantity' => ['kind' => 'quantity', 'key' => 'quantity:1', 'value' => 1, 'unit' => 'm2'],
        ];
    }

    private function invalidPayloads(): array
    {
        return [
            'room' => ['kind' => 'room', 'key' => 'room:invalid', 'polygon' => [[0, 0], [1, 0]]],
            'wall' => ['kind' => 'wall', 'key' => 'wall:invalid', 'start' => [0, 0]],
            'opening' => ['kind' => 'opening', 'key' => 'opening:invalid', 'wall_key' => 'wall:1', 'type' => 'arch', 'width_m' => 1, 'height_m' => 2],
            'dimension' => ['kind' => 'dimension', 'key' => 'dimension:invalid', 'value' => 1, 'unit' => 'ft'],
            'table' => ['kind' => 'table', 'key' => 'table:invalid', 'columns' => [], 'rows' => []],
            'structural_element' => ['kind' => 'structural_element', 'key' => 'structural_element:invalid', 'type' => 'beam'],
            'quantity' => ['kind' => 'quantity', 'key' => 'quantity:invalid', 'value' => 0, 'unit' => 'm2'],
        ];
    }

    private function insertEntity(array $fixture, string $kind, array $payload): int
    {
        return (int) DB::table('estimate_generation_project_model_entities')->insertGetId([
            'building_model_id' => $fixture['building_model_id'],
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'],
            'source_version' => $fixture['source_version'],
            'stable_key' => $payload['key'],
            'entity_kind' => $kind,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'confidence' => 0.9,
            'created_at' => now(),
        ]);
    }

    private function insertEvidenceBinding(array $fixture, int $entityId, int $assertionId): void
    {
        DB::table('estimate_generation_project_model_evidence_bindings')->insert($this->evidenceBindingRow($fixture, $entityId, $assertionId));
    }

    private function evidenceBindingRow(array $fixture, int $entityId, int $assertionId): array
    {
        return [
            'building_model_id' => $fixture['building_model_id'],
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'],
            'source_version' => $fixture['source_version'],
            'entity_id' => $entityId,
            'evidence_id' => $fixture['evidence_id'],
            'assertion_id' => $assertionId,
            'correction_id' => null,
            'candidate_source' => 'cad',
            'candidate_value_fingerprint' => str_repeat('c', 64),
            'evidence_source_version' => $fixture['evidence_source_version'],
            'evidence_invalidation_version' => 0,
            'created_at' => now(),
        ];
    }

    private function insertCadAssertion(array $fixture, int $entityId, string $stableKey): int
    {
        return (int) DB::table('estimate_generation_project_model_assertions')->insertGetId([
            'building_model_id' => $fixture['building_model_id'],
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'],
            'source_version' => $fixture['source_version'],
            'stable_key' => $stableKey,
            'entity_id' => $entityId,
            'assertion_type' => 'height',
            'payload' => json_encode(['source' => 'cad', 'value' => 2.8, 'unit' => 'm'], JSON_THROW_ON_ERROR),
            'confidence' => 0.9,
            'created_at' => now(),
        ]);
    }

    private function assertRejected(callable $operation): void
    {
        DB::statement('SAVEPOINT project_model_contract');
        try {
            $operation();
            self::fail('Invalid project model data was accepted.');
        } catch (QueryException) {
            DB::statement('ROLLBACK TO SAVEPOINT project_model_contract');
        } finally {
            DB::statement('RELEASE SAVEPOINT project_model_contract');
        }
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_ESTIMATE_GENERATION_POSTGRES_CONTRACT') !== '1'
            || DB::getDriverName() !== 'pgsql'
            || ! str_ends_with((string) DB::getDatabaseName(), '_contract')) {
            self::markTestSkipped('Requires explicit isolated PostgreSQL contract environment.');
        }
    }
}
