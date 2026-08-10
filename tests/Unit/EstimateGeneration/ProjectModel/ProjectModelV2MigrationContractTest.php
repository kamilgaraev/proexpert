<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelV2MigrationContractTest extends TestCase
{
    #[Test]
    public function forward_only_migration_consolidates_existing_project_model_without_parallel_entity_or_evidence_tables(): void
    {
        $migration = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_10_000600_consolidate_estimate_project_model_v2.php'
        );

        foreach ([
            'estimate_generation_project_model_entities',
            'estimate_generation_project_model_assertions',
            'estimate_generation_project_model_evidence_bindings',
            'estimate_generation_project_model_fact_evidence',
            'eg_pm_fact_evidence_scope_guard',
            'fact_origin',
            'fact_status',
            'fact_version',
            'system_actor_key',
            'estimate_generation_project_model_fact_projections',
            'estimate_generation_project_model_conflicts',
            'estimate_generation_project_model_conflict_facts',
            'estimate_generation_project_model_derived_quantities',
            'estimate_generation_project_model_derived_operands',
            'estimate_generation_project_model_cross_document_links',
            "'material'",
            "'equipment'",
            'organization_id',
            'project_id',
            'session_id',
            'source_version',
            'RuntimeException',
        ] as $required) {
            self::assertStringContainsString($required, $migration);
        }

        self::assertStringNotContainsString("Schema::create('estimate_generation_project_model_v2_entities'", $migration);
        self::assertStringNotContainsString("Schema::create('estimate_generation_project_model_v2_evidence'", $migration);
        self::assertStringNotContainsString('Schema::dropIfExists', $migration);
    }
}
