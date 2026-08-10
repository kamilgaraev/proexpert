<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\ProjectModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelAssembler;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelOperationContext;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\BuildingModelRepository;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\GeometryBuildingModelInputMapper;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\InMemoryBuildingModelStore;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\ProjectModelEvidenceWriter;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\SessionBuildingModelBridge;
use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\SessionBuildingModelUnitData;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ApplyProjectModelDecision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Conflict;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Evidence\InMemoryEvidenceRepository;
use App\BusinessModules\Addons\EstimateGeneration\Http\Presentation\ProjectModelReadProjection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class CanonicalProjectModelProductionPathTest extends TestCase
{
    #[Test]
    public function bridge_writes_domain_records_and_replay_repairs_a_missing_current_projection(): void
    {
        [$bridge, $models, $read] = $this->path();
        $context = $this->context();

        $bridge->store($context, [$this->unit()]);

        self::assertContainsOnlyInstancesOf(Entity::class, $models->entities);
        self::assertContainsOnlyInstancesOf(Fact::class, $models->facts);
        self::assertContainsOnlyInstancesOf(Evidence::class, $models->evidence);
        $first = $read->forScope(10, 20, 30);
        self::assertCount(1, $first['facts']);
        self::assertSame(['value' => '7.94', 'unit' => 'm2'], $first['effective_values'][0]['value']);

        $models->removeProjection($first['facts'][0]->id);
        self::assertSame([], $read->forScope(10, 20, 30)['facts']);

        $bridge->store($context, [$this->unit()]);

        self::assertCount(1, $read->forScope(10, 20, 30)['facts']);
    }

    #[Test]
    public function correction_creates_an_immutable_decision_and_new_current_fact_visible_to_readers(): void
    {
        [$bridge, $models, $read] = $this->path();
        $bridge->store($this->context(), [$this->unit()]);
        $before = $read->forScope(10, 20, 30)['facts'][0];

        $decision = (new ApplyProjectModelDecision($models))->apply(
            organizationId: 10,
            projectId: 20,
            sessionId: 30,
            sourceVersion: $before->sourceVersion,
            factId: $before->id,
            value: '8.1',
            unit: 'm2',
            actorId: '42',
            reason: 'Проверено по экспликации помещений',
            decisionId: 'decision:manual-area-1',
        );

        self::assertInstanceOf(Decision::class, $decision);
        self::assertSame($before->evidenceIds, $decision->evidenceIds);
        self::assertCount(1, $models->decisions);
        $after = $read->forScope(10, 20, 30);
        self::assertSame(['value' => '8.1', 'unit' => 'm2'], $after['effective_values'][0]['value']);
        self::assertSame(2, $after['facts'][0]->version);
        self::assertSame('user_assumption', $after['facts'][0]->origin);
    }

    #[Test]
    public function read_projection_is_tenant_scoped_and_exposes_unresolved_conflicts(): void
    {
        [$bridge, $models, $read] = $this->path();
        $bridge->store($this->context(), [$this->unit()]);
        $current = $models->currentFacts(10, 20, 30)[0];
        $other = new Fact(
            'fact:conflicting-area', 10, 20, 30, $current->sourceVersion, $current->entityId,
            $current->type, '8.2', 'm2', 0.9, 'document', 'conflicted', $current->evidenceIds,
        );
        $conflictedCurrent = new Fact(
            $current->id, 10, 20, 30, $current->sourceVersion, $current->entityId,
            $current->type, $current->value, $current->unit, $current->confidence,
            $current->origin, 'conflicted', $current->evidenceIds,
        );
        $models->saveSourceModel([], [$conflictedCurrent, $other], array_values($models->evidence), [
            Conflict::between('conflict:area', [$conflictedCurrent, $other], 'value_mismatch'),
        ]);

        self::assertCount(1, $read->forScope(10, 20, 30)['facts']);
        self::assertCount(1, $read->forScope(10, 20, 30)['conflicts']);
        self::assertSame([], $read->forScope(99, 20, 30)['facts']);
        self::assertArrayHasKey('conflicts', $read->forScope(10, 20, 30));
    }

    private function path(): array
    {
        $evidence = new InMemoryEvidenceRepository;
        $models = new InMemoryProjectModelRepository;
        $buildingModels = new BuildingModelRepository(new InMemoryBuildingModelStore, $evidence, $models);
        $writer = new ProjectModelEvidenceWriter($models, $evidence);
        $bridge = new SessionBuildingModelBridge(
            $evidence,
            new GeometryBuildingModelInputMapper,
            new BuildingModelAssembler,
            $buildingModels,
            $writer,
        );

        return [$bridge, $models, new ProjectModelReadProjection($models)];
    }

    private function context(): BuildingModelOperationContext
    {
        return new BuildingModelOperationContext(10, 20, 30, $this->sourceVersion('f'));
    }

    private function unit(): SessionBuildingModelUnitData
    {
        $source = $this->sourceVersion('a');

        return new SessionBuildingModelUnitData(101, 501, 601, 'sketch', 1, $source, 0.95, [
            'source_kind' => 'sketch',
            'floor_key' => 'floor-1',
            'vision_analysis' => [
                'schema_version' => 1,
                'sheet_type' => 'floor_plan',
                'evidence' => [['key' => 'vision-page', 'locator' => [
                    'page_id' => 601,
                    'page_number' => 1,
                    'processing_unit_id' => 101,
                    'source_version' => $source,
                    'coordinate_space' => 'normalized_source_v1',
                ]]],
                'elements' => [[
                    'key' => 'room_101',
                    'type' => 'room',
                    'label' => 'Кабинет 7,94',
                    'polygon' => [[0.0, 0.0], [1.0, 0.0], [1.0, 1.0]],
                    'confidence' => 0.95,
                    'evidence_ref' => 'vision-page',
                ]],
                'scale_candidates' => [[
                    'source' => 'manual_reference',
                    'meters_per_unit' => 0.001,
                    'confidence' => 1.0,
                    'evidence_ref' => 'vision-page',
                    'detail' => 'confirmed_control_dimension',
                ]],
                'warnings' => [],
                'provider' => 'timeweb',
                'requested_model' => 'vision/model',
                'reported_model' => 'vision/model',
                'model_version' => 'provider:v1',
                'usage' => ['status' => 'unavailable', 'input_tokens' => null, 'output_tokens' => null],
            ],
        ]);
    }

    private function sourceVersion(string $character): string
    {
        return 'sha256:'.str_repeat($character, 64);
    }
}
