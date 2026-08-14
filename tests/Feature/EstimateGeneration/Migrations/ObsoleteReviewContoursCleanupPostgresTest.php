<?php

declare(strict_types=1);

namespace Tests\Feature\EstimateGeneration\Migrations;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

#[Group('postgres-contract')]
final class ObsoleteReviewContoursCleanupPostgresTest extends TestCase
{
    public function createApplication(): Application
    {
        $app = require dirname(__DIR__, 4).'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    #[Test]
    public function fresh_contract_schema_contains_only_canonical_estimator_contours(): void
    {
        self::assertSame('pgsql', DB::getDriverName());
        self::assertSame('most_ai_estimator_contract', DB::getDatabaseName());

        foreach ([
            'estimate_generation_sheet_analysis_operations',
            'estimate_generation_geometry_regeneration_outbox',
            'estimate_generation_geometry_confirmations',
            'estimate_generation_building_model_evidence',
            'estimate_generation_building_models',
        ] as $obsolete) {
            self::assertNull(DB::selectOne('SELECT to_regclass(?) AS relation', [$obsolete])->relation);
        }

        foreach ([
            'estimate_generation_evidence',
            'estimate_generation_project_model_fact_evidence',
            'estimate_generation_project_model_derived_quantities',
            'estimate_generation_ai_role_runs',
            'estimate_change_proposals',
        ] as $canonical) {
            self::assertSame($canonical, DB::selectOne('SELECT to_regclass(?)::text AS relation', [$canonical])->relation);
        }

        self::assertNull(DB::selectOne(
            "SELECT to_regprocedure('eg_project_model_evidence_binding_guard()') AS routine",
        )->routine);
    }

    #[Test]
    public function definition_mismatch_fails_before_any_destructive_statement(): void
    {
        $schema = 'most_cleanup_mismatch_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA "'.$schema.'"');
        DB::unprepared('SET search_path TO "'.$schema.'"');
        DB::statement('CREATE TABLE estimate_generation_sheet_analysis_operations (operation_id uuid PRIMARY KEY)');

        try {
            $migration = require app_path(
                'BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000900_remove_obsolete_estimate_generation_review_contours.php',
            );
            try {
                $migration->up();
                self::fail('Tampered obsolete schema was accepted.');
            } catch (RuntimeException $exception) {
                self::assertSame(
                    'obsolete_estimate_generation_schema_mismatch:estimate_generation_sheet_analysis_operations',
                    $exception->getMessage(),
                );
            }
            self::assertSame(
                'estimate_generation_sheet_analysis_operations',
                DB::selectOne("SELECT to_regclass('estimate_generation_sheet_analysis_operations')::text AS relation")->relation,
            );
        } finally {
            DB::unprepared('SET search_path TO public');
            if (preg_match('/^most_cleanup_mismatch_[a-f0-9]{16}$/D', $schema) === 1) {
                DB::unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
            }
        }
    }

    #[Test]
    public function deployed_block_b_schema_drops_the_historical_model_scope_foreign_key_forward_only(): void
    {
        $schema = 'most_cleanup_upgrade_'.bin2hex(random_bytes(8));
        DB::unprepared('CREATE SCHEMA "'.$schema.'"');
        DB::unprepared('SET search_path TO "'.$schema.'"');

        try {
            $this->createTextTable('estimate_generation_sheet_analysis_operations', [
                'analysis_payload', 'attempt_count', 'completed_at', 'created_at', 'document_id',
                'failure_reason', 'final_routing', 'initial_routing', 'kind', 'lease_expires_at',
                'lease_token', 'operation_id', 'organization_id', 'project_id', 'session_id',
                'source_version', 'status', 'unit_id', 'updated_at',
            ]);
            $this->createTextTable('estimate_generation_geometry_regeneration_outbox', [
                'attempt_count', 'available_at', 'created_at', 'delivered_at', 'generation_attempt_id',
                'id', 'idempotency_key', 'input_version', 'last_error_code', 'model_version',
                'organization_id', 'previous_input_version', 'project_id', 'session_id', 'state_version',
                'status', 'updated_at',
            ]);
            $this->createTextTable('estimate_generation_geometry_confirmations', [
                'actor_id', 'confirmed_at', 'confirmed_building_model_id', 'confirmed_content_version',
                'confirmed_input_version', 'created_at', 'evidence_id', 'id', 'organization_id',
                'previous_building_model_id', 'previous_content_version', 'previous_input_version',
                'project_id', 'reviewer_ref', 'semantic_payload', 'session_id', 'source_class', 'updated_at',
            ]);
            DB::statement('CREATE TABLE estimate_generation_building_models (
                id bigint, organization_id bigint, project_id bigint, session_id bigint, content_version text,
                assumptions text, created_at text, input_version text, metrics text, model text,
                model_version text, scale_meters_per_unit text, scale_status text,
                UNIQUE (id, organization_id, project_id, session_id, content_version)
            )');
            DB::statement('CREATE TABLE estimate_generation_building_model_evidence (
                building_model_id bigint, evidence_id bigint, organization_id bigint, project_id bigint,
                session_id bigint, created_at text,
                UNIQUE (building_model_id, evidence_id, organization_id, project_id, session_id)
            )');
            DB::statement('CREATE TABLE estimate_generation_project_model_evidence_bindings (
                building_model_id bigint, evidence_id bigint, organization_id bigint, project_id bigint,
                session_id bigint, source_version text,
                CONSTRAINT eg_project_model_evidence_model_scope_fk FOREIGN KEY
                    (building_model_id, organization_id, project_id, session_id, source_version)
                    REFERENCES estimate_generation_building_models
                    (id, organization_id, project_id, session_id, content_version),
                CONSTRAINT eg_project_model_evidence_provenance_fk FOREIGN KEY
                    (building_model_id, evidence_id, organization_id, project_id, session_id)
                    REFERENCES estimate_generation_building_model_evidence
                    (building_model_id, evidence_id, organization_id, project_id, session_id)
            )');

            $migration = require app_path(
                'BusinessModules/Addons/EstimateGeneration/migrations/2026_08_14_000900_remove_obsolete_estimate_generation_review_contours.php',
            );
            $migration->up();

            self::assertNull(DB::selectOne("SELECT to_regclass('estimate_generation_building_models') AS relation")->relation);
            self::assertSame(
                'estimate_generation_project_model_evidence_bindings',
                DB::selectOne("SELECT to_regclass('estimate_generation_project_model_evidence_bindings')::text AS relation")->relation,
            );
            self::assertSame(0, DB::table('pg_constraint')->whereIn('conname', [
                'eg_project_model_evidence_model_scope_fk',
                'eg_project_model_evidence_provenance_fk',
            ])->count());
        } finally {
            DB::unprepared('SET search_path TO public');
            if (preg_match('/^most_cleanup_upgrade_[a-f0-9]{16}$/D', $schema) === 1) {
                DB::unprepared('DROP SCHEMA "'.$schema.'" CASCADE');
            }
        }
    }

    /** @param list<string> $columns */
    private function createTextTable(string $table, array $columns): void
    {
        $definition = implode(', ', array_map(static fn (string $column): string => '"'.$column.'" text', $columns));
        DB::statement('CREATE TABLE "'.$table.'" ('.$definition.')');
    }
}
