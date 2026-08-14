<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\EloquentProjectModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EloquentEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceData;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceSourceType;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\EvidenceType;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\AnalysisBasisPayloadService;
use App\BusinessModules\Addons\EstimateGeneration\Models\EstimateGenerationSession;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

#[Group('postgres-contract')]
final class DerivedQuantityCurrentProjectionPostgresTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function production_schema_has_a_scoped_current_derived_quantity_projection(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('most_backend_testing', DB::getDatabaseName());
        if (! Schema::hasColumn('estimate_generation_sessions', 'state_version')) {
            DB::statement('ALTER TABLE estimate_generation_sessions ADD COLUMN state_version bigint NOT NULL DEFAULT 0');
        }
        if (! Schema::hasTable('estimate_generation_project_model_derived_quantities')) {
            if (Schema::hasTable('estimate_generation_project_model_entities')) {
                $partial = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000200_create_estimate_generation_project_model_tables.php';
                $partial->down();
            }
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS eg_building_models_projection_scope_uq ON estimate_generation_building_models (id, organization_id, project_id, session_id, content_version)');
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS eg_building_model_evidence_projection_scope_uq ON estimate_generation_building_model_evidence (building_model_id, evidence_id, organization_id, project_id, session_id)');
            foreach ([
                '2026_08_01_000200_create_estimate_generation_project_model_tables.php',
                '2026_08_10_000600_consolidate_estimate_project_model_v2.php',
                '2026_08_10_000610_secure_estimate_project_model_v2_schema.php',
            ] as $file) {
                $prerequisite = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/'.$file;
                $prerequisite->up();
            }
        }
        $this->resetCurrentProjectionMigration();
        (require app_path('BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000200_detach_project_model_from_building_model.php'))->up();
        $legacyId = DB::table('estimate_generation_project_model_derived_quantities')
            ->where('organization_id', 900000001)
            ->where('stable_key', 'quantity:legacy:fixture')
            ->value('id');
        if ($legacyId === null) {
            $legacyId = DB::table('estimate_generation_project_model_derived_quantities')->insertGetId([
                'organization_id' => 900000001,
                'project_id' => 900000001,
                'session_id' => 900000001,
                'source_version' => 'sha256:'.str_repeat('9', 64),
                'stable_key' => 'quantity:legacy:fixture',
                'entity_stable_key' => 'quantity:legacy',
                'formula' => 'legacy:1',
                'value' => '1',
                'unit' => 'm2',
                'rounding_mode' => 'half_up',
                'rounding_scale' => 2,
                'status' => 'confirmed',
                'evidence_lineage' => '[]',
                'unresolved_inputs' => '[]',
                'created_at' => now(),
            ]);
        }
        $migration = require dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_12_000100_add_derived_quantity_current_projection.php';
        $migration->up();
        $migration->up();
        self::assertSame(
            'legacy_unverifiable',
            DB::table('estimate_generation_project_model_derived_quantities')->where('id', $legacyId)->value('identity_status'),
        );
        self::assertTrue(Schema::hasTable('estimate_generation_project_model_derived_quantity_projections'));
        self::assertSame(16, (int) DB::selectOne("SELECT current_setting('server_version_num')::int / 10000 AS major")->major);
        self::assertTrue((bool) DB::table('pg_constraint')->where('conname', 'eg_pm_derived_current_history_fk')->exists());
        self::assertTrue((bool) DB::table('pg_indexes')->where('indexname', 'eg_pm_derived_history_scope_idx')->exists());
    }

    private function resetCurrentProjectionMigration(): void
    {
        DB::statement('DROP TABLE IF EXISTS estimate_generation_project_model_derived_quantity_projections');
        DB::statement('ALTER TABLE estimate_generation_project_model_derived_quantities DROP CONSTRAINT IF EXISTS eg_pm_derived_exact_contract_ck');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_pm_derived_exact_scope_uq');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS eg_pm_derived_history_scope_idx');
        foreach ([
            'logical_key',
            'exact_identity',
            'formula_identity',
            'formula_version',
            'rounding_boundary',
            'unit_compatibility',
            'snapshot_identity',
            'technology_decision_id',
            'identity_status',
        ] as $column) {
            DB::statement("ALTER TABLE estimate_generation_project_model_derived_quantities DROP COLUMN IF EXISTS {$column}");
        }
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS eg_pm_derived_append_trg ON estimate_generation_project_model_derived_quantities;
CREATE TRIGGER eg_pm_derived_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_quantities FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard();
SQL);
    }

    #[Test]
    public function eloquent_projection_switches_atomically_and_preserves_exact_history(): void
    {
        $this->production_schema_has_a_scoped_current_derived_quantity_projection();
        DB::beginTransaction();
        try {
            [$repository, $scope, $entity, $firstFact, $firstEvidence] = $this->fixture();
            $snapshot = $repository->snapshot($scope[0], $scope[1], $scope[2]);
            $coverageFacts = array_values(array_filter(
                $snapshot->facts,
                static fn (Fact $fact): bool => $fact->type === 'geometry_coverage',
            ));
            self::assertCount(1, $coverageFacts);
            self::assertSame('covered_empty', $coverageFacts[0]->value['status'] ?? null);
            self::assertSame('element:42', $snapshot->evidence[0]->nativeReference ?? null);
            self::assertSame(['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], $snapshot->evidence[0]->region ?? null);
            $unknownCoverage = $this->coverageFact(
                $scope, $entity, $firstEvidence, 'fact:coverage:v2', 'unknown', 2, 'fact:coverage:v1',
            );
            $repository->saveSourceModel([], [$unknownCoverage], [$firstEvidence]);
            self::assertSame(
                'unknown',
                $repository->fact($scope[0], $scope[1], $scope[2], $unknownCoverage->id)?->value['status'] ?? null,
            );
            $confirmedCoverage = $this->coverageFact(
                $scope, $entity, $firstEvidence, 'fact:coverage:v3', 'covered_empty', 3, $unknownCoverage->id,
            );
            $repository->saveSourceModel([], [$confirmedCoverage], [$firstEvidence]);
            self::assertSame(
                'covered_empty',
                $repository->fact($scope[0], $scope[1], $scope[2], $confirmedCoverage->id)?->value['status'] ?? null,
            );
            $first = $this->quantity($scope, $entity, $firstFact, $firstEvidence, ['run_id' => 1]);

            $repository->replaceDerivedQuantityProjection($scope[0], $scope[1], $scope[2], $scope[3], [$first], []);
            $repository->replaceDerivedQuantityProjection($scope[0], $scope[1], $scope[2], $scope[3], [$first], []);

            self::assertCount(1, $repository->currentDerivedQuantities($scope[0], $scope[1], $scope[2], $scope[3]));
            self::assertCount(1, $repository->derivedQuantityHistory($scope[0], $scope[1], $scope[2], $scope[3], $first->logicalId));
            $collision = new DerivedQuantity(
                $first->id, $scope[0], $scope[1], $scope[2], $scope[3], $entity->id,
                $first->formula, $first->operands, '6', $first->unit, $first->roundingMode,
                $first->roundingScale, $first->evidenceIds, 'confirmed', $first->formulaIdentity,
                $first->formulaVersion, $first->roundingBoundary, $first->unitCompatibility,
                $first->snapshotIdentity, $first->technologyDecisionId, $first->logicalId, $first->exactIdentity,
            );
            try {
                $repository->replaceDerivedQuantityProjection($scope[0], $scope[1], $scope[2], $scope[3], [$collision], []);
                self::fail('Derived quantity exact identity collision was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }

            $replacementEvidence = new Evidence(
                $firstEvidence->id, $scope[0], $scope[1], $scope[2], $scope[3],
                'artifact:plan', 'cad', 2, ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], 'native:block:42',
            );
            $replacementFact = new Fact(
                'fact:quantity:v2', $scope[0], $scope[1], $scope[2], $scope[3], $entity->id,
                'length', '5.0', 'm', 1.0, 'document', 'confirmed', [$replacementEvidence->id], 2, $firstFact->id,
            );
            $repository->saveSourceModel([], [$replacementFact], [$replacementEvidence]);
            $second = $this->quantity($scope, $entity, $replacementFact, $replacementEvidence, ['run_id' => 2]);
            $repository->replaceDerivedQuantityProjection($scope[0], $scope[1], $scope[2], $scope[3], [$second], []);

            $current = $repository->currentDerivedQuantities($scope[0], $scope[1], $scope[2], $scope[3]);
            self::assertCount(1, $current);
            self::assertSame($second->id, $current[0]->id);
            self::assertCount(2, $repository->derivedQuantityHistory($scope[0], $scope[1], $scope[2], $scope[3], $second->logicalId));
            self::assertSame([], $repository->currentDerivedQuantities($scope[0] + 1000000, $scope[1], $scope[2], $scope[3]));
            $basis = (new AnalysisBasisPayloadService(
                app('db'),
                $repository,
                static fn (string $key): string => $key,
            ))->handle($scope[0], $scope[1], $scope[2], 'quantity', $second->logicalId);
            self::assertSame(0, BigDecimal::of($second->value)->compareTo(BigDecimal::of((string) ($basis['value'] ?? ''))));
            self::assertSame('quantity', $basis['type'] ?? null);
            self::assertSame(1, $basis['sources'][0]['document_id'] ?? null);
            self::assertNull((new AnalysisBasisPayloadService(
                app('db'),
                $repository,
                static fn (string $key): string => $key,
            ))->handle($scope[0] + 1000000, $scope[1], $scope[2], 'quantity', $second->logicalId));

            $repository->replaceDerivedQuantityProjection($scope[0], $scope[1], $scope[2], $scope[3], [], [$second->logicalId]);
            self::assertSame([], $repository->currentDerivedQuantities($scope[0], $scope[1], $scope[2], $scope[3]));
            self::assertCount(2, $repository->derivedQuantityHistory($scope[0], $scope[1], $scope[2], $scope[3], $second->logicalId));

            $plan = DB::select('EXPLAIN (FORMAT JSON) SELECT logical_key FROM estimate_generation_project_model_derived_quantity_projections WHERE organization_id = ? AND project_id = ? AND session_id = ? AND source_version = ? ORDER BY logical_key LIMIT 200', $scope);
            self::assertStringContainsString('eg_pm_derived_current_pk', json_encode($plan, JSON_THROW_ON_ERROR));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function concurrent_equivalent_and_competing_projection_writers_leave_one_valid_current_row(): void
    {
        $this->production_schema_has_a_scoped_current_derived_quantity_projection();
        self::assertTrue(function_exists('pg_connect'));
        $scope = [910000001, 910000001, 910000001, 'sha256:'.str_repeat('8', 64)];
        $logical = 'quantity:concurrency:fixture';
        $firstExact = str_repeat('1', 64);
        $secondExact = str_repeat('2', 64);
        $firstId = $this->insertExactHistoryFixture($scope, $logical, $firstExact, '1');
        $secondId = $this->insertExactHistoryFixture($scope, $logical, $secondExact, '2');
        try {
            DB::beginTransaction();
            try {
                DB::table('estimate_generation_project_model_derived_quantity_projections')->insert([
                    'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2],
                    'source_version' => $scope[3], 'logical_key' => $logical,
                    'derived_quantity_id' => $firstId, 'exact_identity' => $secondExact,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                self::fail('Cross-version current projection was accepted.');
            } catch (QueryException) {
                self::addToAssertionCount(1);
            } finally {
                DB::rollBack();
            }
            $connectionString = sprintf(
                'host=%s port=%s dbname=%s user=%s password=%s',
                getenv('DB_HOST'), getenv('DB_PORT'), getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'),
            );
            $left = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
            $right = pg_connect($connectionString, PGSQL_CONNECT_FORCE_NEW);
            self::assertNotFalse($left);
            self::assertNotFalse($right);

            $this->raceProjectionWriters($left, $right, $scope, $logical, $firstId, $firstExact, $secondId, $secondExact);
            self::assertSame(1, DB::table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $scope[0])->where('project_id', $scope[1])
                ->where('session_id', $scope[2])->where('source_version', $scope[3])
                ->where('logical_key', $logical)->count());
            self::assertSame($secondId, (int) DB::table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $scope[0])->where('logical_key', $logical)->value('derived_quantity_id'));

            $this->raceProjectionWriters($left, $right, $scope, $logical, $secondId, $secondExact, $secondId, $secondExact);
            self::assertSame(1, DB::table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $scope[0])->where('logical_key', $logical)->count());
        } finally {
            DB::table('estimate_generation_project_model_derived_quantity_projections')
                ->where('organization_id', $scope[0])->where('logical_key', $logical)->delete();
            DB::statement('DROP TRIGGER IF EXISTS eg_pm_derived_append_trg ON estimate_generation_project_model_derived_quantities');
            DB::table('estimate_generation_project_model_derived_quantities')
                ->whereIn('id', [$firstId, $secondId])->delete();
            DB::statement('CREATE TRIGGER eg_pm_derived_append_trg BEFORE UPDATE OR DELETE ON estimate_generation_project_model_derived_quantities FOR EACH ROW EXECUTE FUNCTION eg_project_model_append_guard()');
        }
    }

    /** @param array{int,int,int,string} $scope */
    private function insertExactHistoryFixture(array $scope, string $logical, string $exact, string $value): int
    {
        return (int) DB::table('estimate_generation_project_model_derived_quantities')->insertGetId([
            'organization_id' => $scope[0], 'project_id' => $scope[1], 'session_id' => $scope[2],
            'source_version' => $scope[3], 'stable_key' => 'quantityv:'.$exact, 'logical_key' => $logical,
            'exact_identity' => $exact, 'entity_stable_key' => 'quantity:concurrency', 'formula' => 'fixture:1',
            'formula_identity' => 'fixture', 'formula_version' => '1', 'value' => $value, 'unit' => 'm2',
            'rounding_mode' => 'half_up', 'rounding_scale' => 2, 'rounding_boundary' => 'formula_result',
            'unit_compatibility' => 'exact', 'snapshot_identity' => '{}', 'technology_decision_id' => null,
            'identity_status' => 'exact', 'status' => 'confirmed', 'evidence_lineage' => '[]',
            'unresolved_inputs' => '[]', 'created_at' => now(),
        ]);
    }

    /** @param array{int,int,int,string} $scope */
    private function raceProjectionWriters(
        mixed $left,
        mixed $right,
        array $scope,
        string $logical,
        int $leftId,
        string $leftExact,
        int $rightId,
        string $rightExact,
    ): void {
        $sql = static fn (int $id, string $exact): string => sprintf(
            "INSERT INTO estimate_generation_project_model_derived_quantity_projections (organization_id, project_id, session_id, source_version, logical_key, derived_quantity_id, exact_identity, created_at, updated_at) VALUES (%d, %d, %d, '%s', '%s', %d, '%s', now(), now()) ON CONFLICT (organization_id, project_id, session_id, source_version, logical_key) DO UPDATE SET derived_quantity_id = EXCLUDED.derived_quantity_id, exact_identity = EXCLUDED.exact_identity, updated_at = now()",
            $scope[0], $scope[1], $scope[2], $scope[3], $logical, $id, $exact,
        );
        self::assertNotFalse(pg_query($left, 'BEGIN'));
        self::assertNotFalse(pg_query($right, 'BEGIN'));
        self::assertNotFalse(pg_query($left, $sql($leftId, $leftExact)));
        self::assertTrue(pg_send_query($right, $sql($rightId, $rightExact)));
        usleep(50000);
        self::assertNotFalse(pg_query($left, 'COMMIT'));
        self::assertNotFalse(pg_get_result($right));
        self::assertNotFalse(pg_query($right, 'COMMIT'));
    }

    /** @return array{EloquentProjectModelRepository,array{int,int,int,string},Entity,Fact,Evidence} */
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
        $storedEvidence = (new EloquentEvidenceRepository(DB::connection()))->insertOrGet(new EvidenceData(
            (int) $organization->id,
            (int) $project->id,
            (int) $session->id,
            EvidenceType::Extracted,
            EvidenceSourceType::DocumentUnit,
            'document:1',
            'sha256:'.str_repeat('a', 64),
            ['document_id' => 1, 'page' => 2, 'bbox' => [10, 20, 40, 60], 'element_key' => 'element:42'],
            ['field_key' => 'wall_length', 'field_value' => 5, 'unit' => 'm'],
            1,
            'pdf_geometry',
            'extractor:v1',
        ));
        $scope = [(int) $organization->id, (int) $project->id, (int) $session->id, 'sha256:'.str_repeat('b', 64)];
        $repository = new EloquentProjectModelRepository(app('db'));
        $entity = new Entity('quantity:1', $scope[0], $scope[1], $scope[2], $scope[3], 'quantity', 'quantity:1', ['value' => 1, 'unit' => 'm2']);
        $evidence = new Evidence(
            'evidence:'.$storedEvidence->id, $scope[0], $scope[1], $scope[2], $scope[3], 'document:1', 'cad', 2,
            ['x' => 10, 'y' => 20, 'width' => 30, 'height' => 40], 'native:block:42',
        );
        $fact = new Fact(
            'fact:quantity:v1', $scope[0], $scope[1], $scope[2], $scope[3], $entity->id, 'length', '5', 'm', 1.0,
            'document', 'confirmed', [$evidence->id],
        );
        $coverage = new Fact(
            'fact:coverage:v1', $scope[0], $scope[1], $scope[2], $scope[3], $entity->id,
            'geometry_coverage', [
                'relation' => 'wall_openings',
                'status' => 'covered_empty',
                'entity_count' => 0,
                'representation' => [
                    'type' => 'cad_geometry',
                    'id' => 'representation:quantity:1',
                    'source_artifact_id' => 'document:1',
                    'source_version' => $scope[3],
                ],
            ], null, 1.0, 'document', 'confirmed', [$evidence->id],
        );
        $repository->saveSourceModel([$entity], [$fact, $coverage], [$evidence]);

        return [$repository, $scope, $entity, $fact, $evidence];
    }

    /** @param array{int,int,int,string} $scope */
    private function quantity(array $scope, Entity $entity, Fact $fact, Evidence $evidence, array $snapshot): DerivedQuantity
    {
        $operand = [
            'fact_id' => $fact->id,
            'entity_id' => $entity->id,
            'role' => 'base_quantity',
            'projection_version' => $fact->version,
            'status' => 'confirmed',
            'current' => true,
            'value' => is_string($fact->value) ? $fact->value : '5',
            'source_value' => is_string($fact->value) ? $fact->value : '5',
            'unit' => 'm',
            'evidence_ids' => [$evidence->id],
            'evidence' => [[
                'source_artifact_id' => $evidence->sourceArtifactId,
                'source_type' => $evidence->sourceType,
                'source_version' => $evidence->sourceVersion,
                'page' => $evidence->page,
                'region' => $evidence->region,
                'native_reference' => $evidence->nativeReference,
            ]],
            'decision_id' => null,
        ];
        $logicalId = 'quantity:technology_work_package:quantity:1';
        $prototype = new DerivedQuantity(
            $logicalId, $scope[0], $scope[1], $scope[2], $scope[3], $entity->id, 'technology_work_package:1', [$operand], '5', 'm',
            'half_up', 2, [$evidence->id], 'confirmed', 'technology_work_package', '1',
            snapshotIdentity: $snapshot,
            logicalId: $logicalId,
        );
        $identity = DerivedQuantityIdentity::for($prototype);

        return new DerivedQuantity(
            'quantityv:'.$identity, $scope[0], $scope[1], $scope[2], $scope[3], $entity->id, 'technology_work_package:1', [$operand], '5', 'm',
            'half_up', 2, [$evidence->id], 'confirmed', 'technology_work_package', '1',
            snapshotIdentity: $snapshot,
            logicalId: $logicalId,
            exactIdentity: $identity,
        );
    }

    /** @param array{int,int,int,string} $scope */
    private function coverageFact(
        array $scope,
        Entity $entity,
        Evidence $evidence,
        string $id,
        string $status,
        int $version,
        string $supersedes,
    ): Fact {
        return new Fact(
            $id, $scope[0], $scope[1], $scope[2], $scope[3], $entity->id, 'geometry_coverage',
            [
                'relation' => 'wall_openings', 'status' => $status, 'entity_count' => 0,
                'representation' => [
                    'type' => 'cad_geometry', 'id' => 'representation:quantity:1:v'.$version,
                    'source_artifact_id' => 'document:1', 'source_version' => $scope[3],
                ],
            ],
            null, 1.0, 'document', 'confirmed', [$evidence->id], $version, $supersedes,
        );
    }
}
