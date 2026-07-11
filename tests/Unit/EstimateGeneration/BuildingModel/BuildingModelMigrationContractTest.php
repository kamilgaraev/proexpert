<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BuildingModelMigrationContractTest extends TestCase
{
    #[Test]
    public function migration_mirrors_identity_tenant_evidence_scale_json_and_immutability_contracts(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_07_11_001000_create_estimate_generation_building_models_table.php'
        );

        foreach ([
            'organization_id', 'project_id', 'session_id', 'input_version', 'model_version', 'content_version',
            'scale_status', 'scale_meters_per_unit', 'model', 'assumptions', 'metrics',
            'eg_building_models_semantic_uq', 'eg_building_models_session_scope_fk',
            'estimate_generation_building_model_evidence', 'eg_building_model_evidence_scope_fk',
            'building-model:v1', 'sha256:[a-f0-9]{64}', "IN ('confirmed','estimated','unknown')",
            'octet_length', 'jsonb_typeof', 'eg_building_model_immutable_guard',
            'estimate_generation.building_model_update_forbidden',
            'estimate_generation.building_model_delete_forbidden', 'pg_trigger_depth()',
            'eg_building_model_json_object_length(model) = 9', "model ?& ARRAY['model_version'", "jsonb_typeof(model->'floors') = 'array'",
            "jsonb_typeof(model->'scale_meters_per_unit')", 'eg_building_model_evidence_active_guard', 'FOR UPDATE',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }
}
