<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelAssertion;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelCorrection;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEntity;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelRelation;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProjectModelContractTest extends TestCase
{
    #[Test]
    public function it_defines_canonical_entities_for_every_project_model_input_kind(): void
    {
        foreach (['room', 'wall', 'opening', 'dimension', 'table', 'structural_element', 'quantity'] as $kind) {
            $entity = new ProjectModelEntity(
                organizationId: 1,
                projectId: 2,
                sessionId: 3,
                sourceVersion: 'sha256:'.str_repeat('a', 64),
                stableKey: 'floor-1-'.$kind,
                kind: $kind,
                payload: ['kind' => $kind],
                evidence: ['document:17:page:1'],
            );

            self::assertSame($kind, $entity->kind);
            self::assertSame('floor-1-'.$kind, $entity->stableKey);
        }
    }

    #[Test]
    public function it_keeps_assertions_relations_and_corrections_in_the_same_versioned_scope(): void
    {
        $sourceVersion = 'sha256:'.str_repeat('b', 64);
        $assertion = new ProjectModelAssertion(1, 2, 3, $sourceVersion, 'assertion:room-1:area', 'room-1', 'area', ['value' => 12.5, 'unit' => 'm2'], ['document:17:page:1'], 0.95);
        $relation = new ProjectModelRelation(1, 2, 3, $sourceVersion, 'relation:opening-1:hosted_by:wall-1', 'opening-1', 'wall-1', 'hosted_by', ['offset_m' => 1.2]);
        $correction = new ProjectModelCorrection(1, 2, 3, $sourceVersion, 'correction:room-1:area:1', 'assertion:room-1:area', 'manual', ['value' => 13.0, 'unit' => 'm2'], 'Проверено по рабочему чертежу', 42);

        self::assertSame($sourceVersion, $assertion->sourceVersion);
        self::assertSame('hosted_by', $relation->relationType);
        self::assertSame('assertion:room-1:area', $correction->assertionStableKey);
    }

    #[Test]
    public function it_rejects_unstable_keys_and_non_canonical_source_versions(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProjectModelEntity(1, 2, 3, 'draft', 'Room 1', 'room', ['kind' => 'room'], ['document:17:page:1']);
    }

    #[Test]
    public function migration_persists_scoped_jsonb_audit_records_with_integrity_guards(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/migrations/2026_08_01_000200_create_estimate_generation_project_model_tables.php'
        );

        foreach ([
            'estimate_generation_project_model_entities',
            'estimate_generation_project_model_assertions',
            'estimate_generation_project_model_relations',
            'estimate_generation_project_model_corrections',
            'organization_id', 'project_id', 'session_id', 'source_version', 'stable_key',
            "'room', 'wall', 'opening', 'dimension', 'table', 'structural_element', 'quantity'",
            'jsonb', 'eg_project_model_entities_scope_key_uq',
            'eg_project_model_assertions_scope_key_uq',
            'eg_project_model_relations_scope_key_uq',
            'eg_project_model_corrections_scope_key_uq',
            'eg_project_model_entity_append_guard',
            'eg_project_model_assertion_append_guard',
            'eg_project_model_relation_append_guard',
            'eg_project_model_correction_append_guard',
            'source_version ~ \'^sha256:[a-f0-9]{64}$\'',
            'jsonb_typeof(payload) = \'object\'',
            'estimate_generation.project_model_update_forbidden',
            'estimate_generation.project_model_delete_forbidden',
        ] as $required) {
            self::assertStringContainsString($required, $source);
        }
    }
}
