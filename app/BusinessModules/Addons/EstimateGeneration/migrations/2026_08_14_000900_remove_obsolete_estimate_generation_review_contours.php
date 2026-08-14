<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const EXPECTED_COLUMNS = [
        'estimate_generation_sheet_analysis_operations' => [
            'analysis_payload', 'attempt_count', 'completed_at', 'created_at', 'document_id',
            'failure_reason', 'final_routing', 'initial_routing', 'kind', 'lease_expires_at',
            'lease_token', 'operation_id', 'organization_id', 'project_id', 'session_id',
            'source_version', 'status', 'unit_id', 'updated_at',
        ],
        'estimate_generation_geometry_regeneration_outbox' => [
            'attempt_count', 'available_at', 'created_at', 'delivered_at', 'generation_attempt_id',
            'id', 'idempotency_key', 'input_version', 'last_error_code', 'model_version',
            'organization_id', 'previous_input_version', 'project_id', 'session_id', 'state_version',
            'status', 'updated_at',
        ],
        'estimate_generation_geometry_confirmations' => [
            'actor_id', 'confirmed_at', 'confirmed_building_model_id', 'confirmed_content_version',
            'confirmed_input_version', 'created_at', 'evidence_id', 'id', 'organization_id',
            'previous_building_model_id', 'previous_content_version', 'previous_input_version',
            'project_id', 'reviewer_ref', 'semantic_payload', 'session_id', 'source_class', 'updated_at',
        ],
        'estimate_generation_building_model_evidence' => [
            'building_model_id', 'created_at', 'evidence_id', 'organization_id', 'project_id', 'session_id',
        ],
        'estimate_generation_building_models' => [
            'assumptions', 'content_version', 'created_at', 'id', 'input_version', 'metrics', 'model',
            'model_version', 'organization_id', 'project_id', 'scale_meters_per_unit', 'scale_status',
            'session_id',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \RuntimeException('obsolete_estimate_generation_cleanup_requires_postgresql');
        }

        foreach (self::EXPECTED_COLUMNS as $table => $expected) {
            $this->assertExactColumns($table, $expected);
        }

        $this->assertConstraint(
            'estimate_generation_project_model_evidence_bindings',
            'eg_project_model_evidence_provenance_fk',
        );

        DB::statement('ALTER TABLE estimate_generation_project_model_evidence_bindings DROP CONSTRAINT eg_project_model_evidence_provenance_fk');
        DB::statement('DROP TRIGGER IF EXISTS eg_project_model_evidence_binding_guard_trg ON estimate_generation_project_model_evidence_bindings');
        DB::statement('DROP FUNCTION IF EXISTS eg_project_model_evidence_binding_guard()');
        DB::statement('DROP INDEX IF EXISTS eg_sheet_analysis_audit_transition_uq');

        DB::statement('DROP TABLE estimate_generation_geometry_confirmations');
        DB::statement('DROP TABLE estimate_generation_geometry_regeneration_outbox');
        DB::statement('DROP TABLE estimate_generation_building_model_evidence');
        DB::statement('DROP TABLE estimate_generation_building_models');
        DB::statement('DROP TABLE estimate_generation_sheet_analysis_operations');

        foreach ([
            'eg_geometry_confirmation_guard_v1()',
            'eg_geometry_confirmation_semantic_valid_v1(jsonb)',
            'eg_building_model_evidence_append_guard()',
            'eg_building_model_evidence_active_guard()',
            'eg_building_model_immutable_guard()',
            'eg_building_model_semantic_guard()',
            'eg_building_model_json_object_length(jsonb)',
        ] as $function) {
            DB::statement('DROP FUNCTION IF EXISTS '.$function);
        }
    }

    public function down(): void
    {
        throw new \RuntimeException('Obsolete estimate generation contour cleanup is forward-only.');
    }

    /** @param list<string> $expected */
    private function assertExactColumns(string $table, array $expected): void
    {
        $rows = DB::select(
            <<<'SQL'
SELECT column_name
FROM information_schema.columns
WHERE table_schema = current_schema() AND table_name = ?
ORDER BY column_name
SQL,
            [$table],
        );
        $actual = array_map(static fn (object $row): string => (string) $row->column_name, $rows);
        sort($expected, SORT_STRING);

        if ($actual !== $expected) {
            throw new \RuntimeException('obsolete_estimate_generation_schema_mismatch:'.$table);
        }
    }

    private function assertConstraint(string $table, string $constraint): void
    {
        $found = DB::selectOne(
            <<<'SQL'
SELECT 1
FROM pg_constraint constraint_definition
JOIN pg_class relation ON relation.oid = constraint_definition.conrelid
JOIN pg_namespace namespace ON namespace.oid = relation.relnamespace
WHERE namespace.nspname = current_schema()
  AND relation.relname = ?
  AND constraint_definition.conname = ?
SQL,
            [$table, $constraint],
        );

        if ($found === null) {
            throw new \RuntimeException('obsolete_estimate_generation_schema_mismatch:'.$constraint);
        }
    }
};
