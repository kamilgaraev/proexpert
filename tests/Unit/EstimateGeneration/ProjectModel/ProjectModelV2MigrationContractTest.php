<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelV2MigrationContractTest extends TestCase
{
    #[Test]
    public function staged_forward_only_migrations_have_bounded_retry_safe_cutover(): void
    {
        $directory = dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/';
        $schema = (string) file_get_contents($directory.'2026_08_10_000600_consolidate_estimate_project_model_v2.php');
        $secure = (string) file_get_contents($directory.'2026_08_10_000610_secure_estimate_project_model_v2_schema.php');
        $backfill = (string) file_get_contents($directory.'2026_08_10_000620_backfill_estimate_project_model_v2.php');
        $finalize = (string) file_get_contents($directory.'2026_08_10_000630_finalize_estimate_project_model_v2_constraints.php');
        $all = $schema.$secure.$backfill.$finalize;

        foreach ([
            'estimate_generation_project_model_entities',
            'estimate_generation_project_model_assertions',
            'estimate_generation_project_model_fact_evidence',
            'estimate_generation_project_model_fact_projections',
            'estimate_generation_project_model_conflicts',
            'estimate_generation_project_model_derived_quantities',
            'estimate_generation_project_model_cross_document_links',
            'estimate_generation_project_understanding_runs',
            "string('match_key', 1000)",
            "'material'",
            "'equipment'",
            'RuntimeException',
        ] as $required) {
            self::assertStringContainsString($required, $all);
        }
        self::assertStringContainsString('CREATE INDEX CONCURRENTLY', $secure.$finalize);
        self::assertStringContainsString('CREATE UNIQUE INDEX CONCURRENTLY', $secure.$finalize);
        self::assertStringContainsString('NOT VALID', $secure.$finalize);
        self::assertStringContainsString('VALIDATE CONSTRAINT', $finalize);
        self::assertStringContainsString('LIMIT 500', $backfill);
        self::assertGreaterThanOrEqual(5, substr_count($backfill, 'LIMIT 500'));
        self::assertStringContainsString('FOR UPDATE SKIP LOCKED', $backfill);
        self::assertStringContainsString('ON CONFLICT', $backfill);
        self::assertStringContainsString('SELECT max(latest.id)', $backfill);
        self::assertStringContainsString("fact.fact_status = 'conflicted'", $backfill);
        self::assertStringContainsString('indisvalid', $secure.$finalize);
        self::assertStringNotContainsString('Schema::dropIfExists', $all);
        self::assertStringNotContainsString('UPDATE estimate_generation_project_model_assertions SET', $all);
        self::assertStringContainsString('App\\Contracts\\Database\\ForwardOnlyMigration', $all);
        self::assertStringContainsString('eg_pm_understanding_one_current_uq', $secure);
        self::assertStringContainsString('eg_pm_facts_backfill_idx', $secure);
        self::assertStringContainsString('RESET lock_timeout', $secure.$backfill.$finalize);
        self::assertStringContainsString('RESET statement_timeout', $secure.$backfill.$finalize);
        self::assertStringNotContainsString('row_number() OVER', $backfill);
        self::assertStringNotContainsString('WITH candidates AS', $backfill);
        self::assertStringContainsString('workset', $backfill);
        self::assertStringContainsString('selected_fact_stable_key', $backfill);
        self::assertStringContainsString('evidence_lineage', $backfill);
        self::assertStringContainsString('historical_evidence_unproven', $backfill);
        self::assertStringContainsString('evidence.source_version = binding.evidence_source_version', $backfill);
        self::assertStringContainsString('evidence.invalidation_version = binding.evidence_invalidation_version', $backfill);
        self::assertStringContainsString('evidence.invalidated_at IS NULL', $backfill);
        self::assertStringContainsString('selected_fact_id AS fact_id', $backfill);
        self::assertStringContainsString('LEFT JOIN LATERAL', $backfill);
        self::assertStringContainsString('ORDER BY candidate.conflict_version DESC, candidate.id DESC', $backfill);
    }

    #[Test]
    public function entity_guard_preserves_kind_specific_validation_and_rejects_unknown_shape(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000610_secure_estimate_project_model_v2_schema.php'
        );
        foreach ([
            "WHEN 'room'", "payload->'polygon'", "payload->'area_m2'",
            "WHEN 'wall'", "payload->'start'", "payload->'end'",
            "WHEN 'opening'", "payload->>'wall_key'", "payload->'width_m'", "payload->'height_m'",
            "WHEN 'dimension'", "WHEN 'quantity'", "payload->>'unit'",
            "WHEN 'material'", "payload->>'material_code'", "payload->'properties'",
            "WHEN 'equipment'", "payload->>'equipment_code'",
            "WHEN 'table'", 'jsonb_array_elements',
            "WHEN 'structural_element'", "payload->'location'",
            'NEW.payload - ARRAY',
            'octet_length(NEW.payload::text) <= 1048576',
        ] as $required) {
            self::assertStringContainsString($required, $migration);
        }
    }
}
