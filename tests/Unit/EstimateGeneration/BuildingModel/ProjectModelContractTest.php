<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelAssertion;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrection;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEntity;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceBinding;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelRelation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelContractTest extends TestCase
{
    #[Test]
    public function it_defines_versioned_entities_for_every_project_model_input_kind(): void
    {
        foreach ($this->validPayloads() as $kind => $payload) {
            $entity = new ProjectModelEntity(
                buildingModelId: 10,
                organizationId: 1,
                projectId: 2,
                sessionId: 3,
                sourceVersion: $this->sourceVersion(),
                stableKey: 'floor-1-'.$kind,
                kind: $kind,
                payload: $payload,
            );

            self::assertSame($kind, $entity->kind);
            self::assertSame('floor-1-'.$kind, $entity->stableKey);
            self::assertSame(10, $entity->buildingModelId);
        }
    }

    #[Test]
    public function it_rejects_incomplete_payloads_for_every_entity_kind(): void
    {
        foreach ($this->validPayloads() as $kind => $payload) {
            unset($payload[array_key_first(array_diff(array_keys($payload), ['kind', 'key']))]);

            try {
                new ProjectModelEntity(10, 1, 2, 3, $this->sourceVersion(), 'floor-1-'.$kind, $kind, $payload);
                self::fail("{$kind} payload without its required domain field was accepted.");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function it_keeps_assertions_relations_corrections_and_evidence_bindings_in_the_same_building_model_version(): void
    {
        $sourceVersion = $this->sourceVersion();
        $assertion = new ProjectModelAssertion(10, 1, 2, 3, $sourceVersion, 'assertion:room-1:area', 'room-1', 'area', ['value' => 12.5, 'unit' => 'm2'], 0.95);
        $relation = new ProjectModelRelation(10, 1, 2, 3, $sourceVersion, 'relation:opening-1:hosted_by:wall-1', 'opening-1', 'wall-1', 'hosted_by', ['offset_m' => 1.2]);
        $correction = new ProjectModelCorrection(10, 1, 2, 3, $sourceVersion, 'correction:room-1:area:1', 'assertion:room-1:area', 'manual', ['value' => 13.0, 'unit' => 'm2'], 'Проверено по рабочему чертежу', 42);
        $binding = new ProjectModelEvidenceBinding(10, 1, 2, 3, $sourceVersion, 'room-1', 'assertion:room-1:area', null, 17, 'cad', \App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelValueFingerprint::for(['value' => 12.5, 'unit' => 'm2']), 'sha256:'.str_repeat('c', 64), 0);

        self::assertSame($sourceVersion, $assertion->sourceVersion);
        self::assertSame('hosted_by', $relation->relationType);
        self::assertSame('assertion:room-1:area', $correction->assertionStableKey);
        self::assertSame(17, $binding->evidenceId);
    }

    #[Test]
    public function it_rejects_unstable_keys_and_non_canonical_source_versions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProjectModelEntity(10, 1, 2, 3, 'draft', 'Room 1', 'room', ['kind' => 'room', 'key' => 'Room 1', 'area_m2' => 12]);
    }

    #[Test]
    public function migration_uses_the_building_model_as_the_single_versioned_source_and_binds_auditable_evidence(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000200_create_estimate_generation_project_model_tables.php'
        );
        $indexMigration = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000150_add_project_model_projection_scope_indexes.php'
        );
        $exactBindingMigration = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000250_bind_project_model_evidence_to_exact_candidate.php'
        );
        $correctionScopeMigration = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000225_add_project_model_correction_scope_unique.php'
        );

        foreach ([
            'estimate_generation_project_model_entities',
            'estimate_generation_project_model_assertions',
            'estimate_generation_project_model_relations',
            'estimate_generation_project_model_corrections',
            'estimate_generation_project_model_evidence_bindings',
            "['id', 'organization_id', 'project_id', 'session_id', 'content_version']",
            "['building_model_id', 'organization_id', 'project_id', 'session_id', 'source_version']",
            'eg_building_models_projection_scope_uq',
            'eg_building_model_evidence_projection_scope_uq',
            'eg_project_model_evidence_provenance_fk',
            'evidence_source_version',
            'evidence_invalidation_version',
            'eg_project_model_evidence_binding_guard',
            'estimate_generation.project_model_evidence_snapshot_invalid',
            'estimate_generation.project_model_entity_payload_invalid',
            'estimate_generation.project_model_update_forbidden',
            'estimate_generation.project_model_delete_forbidden',
            "WHEN 'room' THEN",
            "WHEN 'wall' THEN",
            "WHEN 'opening' THEN",
            "WHEN 'dimension' THEN",
            "WHEN 'table' THEN",
            "WHEN 'structural_element' THEN",
            "WHEN 'quantity' THEN",
            'CREATE TRIGGER eg_project_model_entity_payload_guard_trg BEFORE INSERT OR UPDATE',
            'FOR UPDATE;',
            'PERFORM 1',
            'FROM estimate_generation_building_model_evidence',
            "Schema::dropIfExists('estimate_generation_project_model_evidence_bindings')",
            "DROP FUNCTION IF EXISTS eg_project_model_entity_payload_guard()",
            "DROP FUNCTION IF EXISTS eg_project_model_append_guard()",
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
        self::assertStringNotContainsString('$table->jsonb(\'evidence\')', $source);
        self::assertStringNotContainsString('$table->unique([\'entity_id\', \'evidence_id\'], \'eg_project_model_evidence_binding_uq\')', $source);
        self::assertStringNotContainsString('eg_project_model_entities_payload_ck', $source);

        foreach ([
            'assertion_id',
            'correction_id',
            'candidate_source',
            'candidate_value_fingerprint',
            'eg_project_model_evidence_assertion_scope_fk',
            'eg_project_model_evidence_correction_scope_fk',
            'eg_project_model_evidence_candidate_subject_ck',
            'eg_project_model_evidence_candidate_invalid',
            'eg_project_model_evidence_candidate_binding_uq',
            'COALESCE(assertion_id, 0), COALESCE(correction_id, 0), evidence_id',
            'WHERE num_nonnulls(assertion_id, correction_id) = 1',
            'DROP CONSTRAINT IF EXISTS eg_project_model_evidence_binding_uq',
            'estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_bindings',
            'CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard()',
        ] as $required) {
            self::assertStringContainsString($required, $exactBindingMigration);
        }

        foreach ([
            'public $withinTransaction = false;',
            'eg_project_model_corrections_scope_uq',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_project_model_corrections_scope_uq',
            'DROP INDEX CONCURRENTLY IF EXISTS eg_project_model_corrections_scope_uq',
        ] as $required) {
            self::assertStringContainsString($required, $correctionScopeMigration);
        }

        $entitiesConstraintSection = substr(
            $source,
            (int) strpos($source, 'ALTER TABLE estimate_generation_project_model_entities'),
            (int) strpos($source, 'ALTER TABLE estimate_generation_project_model_assertions') - (int) strpos($source, 'ALTER TABLE estimate_generation_project_model_entities'),
        );
        self::assertStringNotContainsString('SELECT ', $entitiesConstraintSection);
        self::assertStringNotContainsString('EXISTS ', $entitiesConstraintSection);

        foreach ([
            'public $withinTransaction = false;',
            'assertNoDuplicateKeys',
            'configureSessionTimeouts',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_building_models_projection_scope_uq',
            'CREATE UNIQUE INDEX CONCURRENTLY eg_building_model_evidence_projection_scope_uq',
            'DROP INDEX CONCURRENTLY IF EXISTS eg_building_model_evidence_projection_scope_uq',
            'DROP INDEX CONCURRENTLY IF EXISTS eg_building_models_projection_scope_uq',
        ] as $required) {
            self::assertStringContainsString($required, $indexMigration);
        }
    }

    #[Test]
    public function exact_evidence_binding_migration_recovers_partial_online_steps_and_refuses_to_erase_audit_links(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000250_bind_project_model_evidence_to_exact_candidate.php'
        );

        foreach ([
            "Schema::hasColumn(self::TABLE, 'assertion_id')",
            "Schema::hasColumn(self::TABLE, 'correction_id')",
            "Schema::hasColumn(self::TABLE, 'candidate_source')",
            "Schema::hasColumn(self::TABLE, 'candidate_value_fingerprint')",
            "'eg_project_model_evidence_assertion_idx'",
            "'eg_project_model_evidence_correction_idx'",
            'ensureConcurrentIndex(',
            'ensureConstraint(',
            'CREATE OR REPLACE FUNCTION eg_project_model_evidence_binding_guard()',
            'CREATE TRIGGER eg_project_model_evidence_binding_guard_trg BEFORE INSERT',
            'DROP CONSTRAINT IF EXISTS eg_project_model_evidence_binding_uq',
            "whereNotNull('assertion_id')",
            "orWhereNotNull('correction_id')",
            "orWhereNotNull('candidate_source')",
            "orWhereNotNull('candidate_value_fingerprint')",
            'estimate_generation.project_model_evidence_binding_rollback_would_drop_candidate_bindings',
        ] as $required) {
            self::assertStringContainsString($required, $migration);
        }

        self::assertStringNotContainsString("->groupBy(['entity_id', 'evidence_id'])", $migration);
        self::assertStringNotContainsString('duplicate_count', $migration);
    }

    private function sourceVersion(): string
    {
        return 'sha256:'.str_repeat('b', 64);
    }

    private function validPayloads(): array
    {
        return [
            'room' => ['kind' => 'room', 'key' => 'floor-1-room', 'polygon' => [[0, 0], [1, 0], [1, 1]]],
            'wall' => ['kind' => 'wall', 'key' => 'floor-1-wall', 'start' => [0, 0], 'end' => [1, 0]],
            'opening' => ['kind' => 'opening', 'key' => 'floor-1-opening', 'wall_key' => 'floor-1-wall', 'type' => 'door', 'width_m' => 0.9, 'height_m' => 2.1],
            'dimension' => ['kind' => 'dimension', 'key' => 'floor-1-dimension', 'value' => 2.5, 'unit' => 'm'],
            'table' => ['kind' => 'table', 'key' => 'floor-1-table', 'columns' => ['name'], 'rows' => [['name' => 'Кухня']]],
            'structural_element' => ['kind' => 'structural_element', 'key' => 'floor-1-structural_element', 'type' => 'beam', 'length_m' => 4.2],
            'quantity' => ['kind' => 'quantity', 'key' => 'floor-1-quantity', 'value' => 12, 'unit' => 'pcs'],
        ];
    }
}
