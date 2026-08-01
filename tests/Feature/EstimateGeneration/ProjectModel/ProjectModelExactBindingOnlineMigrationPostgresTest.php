<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\FloorData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\NormalizedBuildingModelData;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\EloquentBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelLocatorFingerprint;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint;
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
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\EstimateGeneration\EstimateGenerationContractDatabaseProvisioner;

#[Group('postgres-contract')]
final class ProjectModelExactBindingOnlineMigrationPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function disposable_contract_database_fails_closed_for_interrupted_exact_binding_migration_and_down(): void
    {
        $this->requireEnvironment();
        $root = dirname(__DIR__, 4);
        EstimateGenerationContractDatabaseProvisioner::provision(DB::connection(), $root, 'training');

        $migration225 = require EstimateGenerationContractDatabaseProvisioner::subjectMigration(
            'project-model',
            '2026_08_01_000225_add_project_model_correction_scope_unique.php',
            $root,
        );
        $migration250 = require EstimateGenerationContractDatabaseProvisioner::subjectMigration(
            'project-model',
            '2026_08_01_000250_bind_project_model_evidence_to_exact_candidate.php',
            $root,
        );

        $migration250->down();
        $migration225->down();
        $migration225->up();

        $fixture = $this->fixture();
        $this->invokeMigrationMethod($migration250, 'replaceExactBindingGuard');
        $this->invokeMigrationMethod($migration250, 'ensureExactBindingGuardTrigger');
        $this->addFirstAuditColumn();
        $this->assertRejected(fn () => $this->insertLegacyBinding($fixture, $fixture['entity_id']));

        $this->addRemainingAuditColumns();
        $assertionId = $this->insertCadAssertion($fixture);
        $this->insertUncheckedBinding($fixture, $fixture['entity_id'], $assertionId, 'table');

        try {
            $migration250->up();
            self::fail('Semantically invalid legacy binding completed the exact-binding migration.');
        } catch (RuntimeException $exception) {
            self::assertStringStartsWith('estimate_generation.project_model_evidence_binding_incomplete_audit:', $exception->getMessage());
        }

        $this->deleteUncheckedBindings();
        $this->insertBinding($fixture, $fixture['entity_id'], $assertionId);
        $migration250->up();
        $this->assertExactBindingConstraintsValidated();

        try {
            $migration250->down();
            self::fail('Rollback removed exact-binding audit columns while an exact binding existed.');
        } catch (RuntimeException $exception) {
            self::assertSame('estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_bindings', $exception->getMessage());
        }

        self::assertTrue(Schema::hasColumn('estimate_generation_project_model_evidence_bindings', 'candidate_value_fingerprint'));
    }

    #[Test]
    public function access_exclusive_rollback_lock_blocks_a_concurrent_binding_insert(): void
    {
        $this->requireEnvironment();
        $root = dirname(__DIR__, 4);
        EstimateGenerationContractDatabaseProvisioner::provision(DB::connection(), $root, 'training');

        $fixture = $this->fixture();
        $assertionId = $this->insertCadAssertion($fixture);
        $connection = config('database.connections.'.DB::getDefaultConnection());
        config(['database.connections.project_model_exact_binding_secondary' => $connection]);
        DB::purge('project_model_exact_binding_secondary');
        $secondary = DB::connection('project_model_exact_binding_secondary');
        $secondary->statement("SET lock_timeout = '100ms'");

        DB::beginTransaction();
        try {
            DB::statement('LOCK TABLE estimate_generation_project_model_evidence_bindings IN ACCESS EXCLUSIVE MODE');
            try {
                $secondary->table('estimate_generation_project_model_evidence_bindings')->insert($this->bindingRow($fixture, $fixture['entity_id'], $assertionId));
                self::fail('Concurrent insert bypassed the rollback lock.');
            } catch (QueryException) {
                self::addToAssertionCount(1);
            }
        } finally {
            DB::rollBack();
            DB::purge('project_model_exact_binding_secondary');
        }
    }

    #[Test]
    public function canonical_postgres_fingerprints_accept_794_and_reordered_locator_but_distinguish_other_evidence(): void
    {
        $this->requireEnvironment();
        $root = dirname(__DIR__, 4);
        EstimateGenerationContractDatabaseProvisioner::provision(DB::connection(), $root, 'training');
        foreach (['2026_08_01_000225_add_project_model_correction_scope_unique.php', '2026_08_01_000250_bind_project_model_evidence_to_exact_candidate.php', '2026_08_01_000275_bind_project_model_evidence_to_canonical_locator.php'] as $migration) {
            (require EstimateGenerationContractDatabaseProvisioner::subjectMigration('project-model', $migration, $root))->up();
        }
        $locator = ['document_id' => 1, 'unit_index' => 2, 'page' => 2, 'region_key' => 'region:'.str_repeat('a', 64), 'element_key' => 'element:'.str_repeat('b', 64), 'bbox' => [1, 2, 3, 4]];
        $reordered = ['bbox' => [1.0, 2.0, 3.0, 4.0], 'element_key' => 'element:'.str_repeat('b', 64), 'page' => 2, 'document_id' => 1, 'region_key' => 'region:'.str_repeat('a', 64), 'unit_index' => 2];
        $wrongLocator = [...$locator, 'element_key' => 'element:'.str_repeat('c', 64)];

        self::assertSame(ProjectModelValueFingerprint::for(['value' => 7.94, 'unit' => 'm2']), DB::scalar("SELECT eg_project_model_value_fingerprint('{\"unit\":\"m2\",\"value\":7.94}'::jsonb)"));
        self::assertSame(ProjectModelValueFingerprint::for(['value' => 7.94, 'unit' => 'm2']), DB::scalar("SELECT eg_project_model_value_fingerprint('{\"unit\":\"m2\",\"value\":7.9400000000001}'::jsonb)"));
        self::assertSame(ProjectModelLocatorFingerprint::for($locator), DB::scalar('SELECT eg_project_model_locator_fingerprint(?::jsonb)', [json_encode($reordered, JSON_THROW_ON_ERROR)]));
        self::assertNotSame(ProjectModelLocatorFingerprint::for($locator), ProjectModelLocatorFingerprint::for($wrongLocator));
        self::assertNotSame(ProjectModelValueFingerprint::for(['value' => 7.94, 'unit' => 'm2']), ProjectModelValueFingerprint::for(['value' => 7.95, 'unit' => 'm2']));
    }

    private function addFirstAuditColumn(): void
    {
        Schema::table('estimate_generation_project_model_evidence_bindings', function ($table): void {
            $table->unsignedBigInteger('assertion_id')->nullable()->after('entity_id');
        });
    }

    private function addRemainingAuditColumns(): void
    {
        Schema::table('estimate_generation_project_model_evidence_bindings', function ($table): void {
            $table->unsignedBigInteger('correction_id')->nullable()->after('assertion_id');
            $table->string('candidate_source', 32)->nullable()->after('evidence_id');
            $table->char('candidate_value_fingerprint', 64)->nullable()->after('candidate_source');
        });
    }

    private function assertExactBindingConstraintsValidated(): void
    {
        foreach ([
            'eg_project_model_evidence_assertion_scope_fk',
            'eg_project_model_evidence_correction_scope_fk',
            'eg_project_model_evidence_candidate_subject_ck',
            'eg_project_model_evidence_candidate_source_ck',
            'eg_project_model_evidence_candidate_fingerprint_ck',
            'eg_project_model_evidence_candidate_version_ck',
        ] as $constraint) {
            self::assertTrue((bool) DB::table('pg_constraint')->where('conname', $constraint)->value('convalidated'), $constraint);
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

        $fixture = [
            'organization_id' => (int) $organization->id,
            'project_id' => (int) $project->id,
            'session_id' => (int) $session->id,
            'building_model_id' => $model->id,
            'source_version' => $model->contentVersion,
            'evidence_id' => $evidence->id,
            'evidence_source_version' => 'sha256:'.str_repeat('a', 64),
        ];
        $fixture['entity_id'] = $this->insertEntity($fixture, 'room:initial');

        return $fixture;
    }

    private function insertEntity(array $fixture, string $key): int
    {
        return (int) DB::table('estimate_generation_project_model_entities')->insertGetId([
            'building_model_id' => $fixture['building_model_id'],
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'],
            'source_version' => $fixture['source_version'],
            'stable_key' => $key,
            'entity_kind' => 'room',
            'payload' => json_encode(['kind' => 'room', 'key' => $key, 'polygon' => [[0, 0], [1, 0], [1, 1]]], JSON_THROW_ON_ERROR),
            'confidence' => 0.9,
            'created_at' => now(),
        ]);
    }

    private function insertCadAssertion(array $fixture): int
    {
        return (int) DB::table('estimate_generation_project_model_assertions')->insertGetId([
            'building_model_id' => $fixture['building_model_id'],
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'],
            'source_version' => $fixture['source_version'],
            'stable_key' => 'assertion:room:height',
            'entity_id' => $fixture['entity_id'],
            'assertion_type' => 'height',
            'payload' => json_encode(['source' => 'cad', 'value' => 2.8, 'unit' => 'm'], JSON_THROW_ON_ERROR),
            'confidence' => 0.9,
            'created_at' => now(),
        ]);
    }

    private function insertBinding(array $fixture, int $entityId, ?int $assertionId = null): void
    {
        DB::table('estimate_generation_project_model_evidence_bindings')->insert($this->bindingRow($fixture, $entityId, $assertionId));
    }

    private function bindingRow(array $fixture, int $entityId, ?int $assertionId = null): array
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
            'candidate_source' => $assertionId === null ? null : 'cad',
            'candidate_value_fingerprint' => $assertionId === null ? null : str_repeat('c', 64),
            'evidence_source_version' => $fixture['evidence_source_version'],
            'evidence_invalidation_version' => 0,
            'created_at' => now(),
        ];
    }

    private function insertLegacyBinding(array $fixture, int $entityId): void
    {
        DB::table('estimate_generation_project_model_evidence_bindings')->insert([
            'building_model_id' => $fixture['building_model_id'],
            'organization_id' => $fixture['organization_id'],
            'project_id' => $fixture['project_id'],
            'session_id' => $fixture['session_id'],
            'source_version' => $fixture['source_version'],
            'entity_id' => $entityId,
            'evidence_id' => $fixture['evidence_id'],
            'evidence_source_version' => $fixture['evidence_source_version'],
            'evidence_invalidation_version' => 0,
            'created_at' => now(),
        ]);
    }

    private function insertUncheckedBinding(array $fixture, int $entityId, int $assertionId, string $candidateSource): void
    {
        DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings DISABLE TRIGGER eg_project_model_evidence_binding_guard_trg');
        try {
            DB::table('estimate_generation_project_model_evidence_bindings')->insert([
                'building_model_id' => $fixture['building_model_id'],
                'organization_id' => $fixture['organization_id'],
                'project_id' => $fixture['project_id'],
                'session_id' => $fixture['session_id'],
                'source_version' => $fixture['source_version'],
                'entity_id' => $entityId,
                'evidence_id' => $fixture['evidence_id'],
                'assertion_id' => $assertionId,
                'correction_id' => null,
                'candidate_source' => $candidateSource,
                'candidate_value_fingerprint' => str_repeat('c', 64),
                'evidence_source_version' => $fixture['evidence_source_version'],
                'evidence_invalidation_version' => 0,
                'created_at' => now(),
            ]);
        } finally {
            DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings ENABLE TRIGGER eg_project_model_evidence_binding_guard_trg');
        }
    }

    private function deleteUncheckedBindings(): void
    {
        DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings DISABLE TRIGGER eg_project_model_evidence_binding_append_trg');
        try {
            DB::table('estimate_generation_project_model_evidence_bindings')->delete();
        } finally {
            DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings ENABLE TRIGGER eg_project_model_evidence_binding_append_trg');
        }
    }

    private function invokeMigrationMethod(object $migration, string $method): void
    {
        $reflection = new \ReflectionMethod($migration, $method);
        $reflection->invoke($migration);
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            self::fail('Incomplete exact binding was accepted after partial migration recovery.');
        } catch (QueryException) {
            self::addToAssertionCount(1);
        }
    }

    private function requireEnvironment(): void
    {
        if (getenv('RUN_PROJECT_MODEL_EXACT_BINDING_POSTGRES_CONTRACT') !== '1'
            || getenv('RUN_ESTIMATE_GENERATION_CONTRACT_PROVISIONER') !== '1'
            || DB::getDriverName() !== 'pgsql'
            || ! str_ends_with((string) DB::getDatabaseName(), '_contract')) {
            self::markTestSkipped('Requires an explicit disposable PostgreSQL project-model migration contract environment.');
        }
    }
}
