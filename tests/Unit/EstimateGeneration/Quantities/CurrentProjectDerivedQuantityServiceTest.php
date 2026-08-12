<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackage;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\CurrentProjectDerivedQuantityService;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class CurrentProjectDerivedQuantityServiceTest extends TestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function production_projection_treats_no_openings_as_empty_but_keeps_partial_opening_unresolved(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $wall = new Entity('wall:1', 10, 20, 30, self::SOURCE, 'wall', 'wall:1');
        $lengthEvidence = new Evidence('evidence:wall-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'wall:1');
        $heightEvidence = new Evidence('evidence:wall-height', 10, 20, 30, self::SOURCE, 'artifact:section', 'cad', 2, null, 'wall:1');
        $repository->saveSourceModel([$wall], [
            new Fact('fact:wall-length', 10, 20, 30, self::SOURCE, $wall->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            new Fact('fact:wall-height', 10, 20, 30, self::SOURCE, $wall->id, 'height', '3', 'm', 1.0, 'document', 'confirmed', [$heightEvidence->id]),
        ], [$lengthEvidence, $heightEvidence]);
        $this->makeStageFiveCurrent($repository);

        $withoutOpenings = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertSame(
            '15',
            $withoutOpenings['quantities']['net_wall_area']->amount ?? null,
            json_encode(['keys' => array_keys($withoutOpenings['quantities']), 'warnings' => $withoutOpenings['warnings']], JSON_THROW_ON_ERROR),
        );
        self::assertSame([], $withoutOpenings['warnings']);

        $partial = new Entity('opening:partial', 10, 20, 30, self::SOURCE, 'opening', 'opening:partial', [
            'wall_id' => $wall->id,
        ]);
        $widthEvidence = new Evidence('evidence:opening-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'opening:partial');
        $repository->saveSourceModel([$partial], [
            new Fact('fact:opening-width', 10, 20, 30, self::SOURCE, $partial->id, 'width', '2', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
        ], [$widthEvidence]);
        $this->makeStageFiveCurrent($repository);

        $withPartialOpening = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertArrayNotHasKey('net_wall_area', $withPartialOpening['quantities']);
        self::assertSame('canonical_quantity_unresolved', $withPartialOpening['warnings'][0]['code'] ?? null);
        self::assertSame('geometry_operand_missing', $withPartialOpening['warnings'][0]['inputs'][0]['code'] ?? null);
        self::assertSame('opening:partial', $withPartialOpening['warnings'][0]['inputs'][0]['entity_id'] ?? null);
        self::assertSame('height', $withPartialOpening['warnings'][0]['inputs'][0]['missing_operand'] ?? null);
        self::assertSame('opening:partial', $withPartialOpening['warnings'][0]['inputs'][0]['source_locator']['native_reference'] ?? null);
    }

    #[Test]
    public function meaningful_operand_replacement_creates_a_new_exact_quantity_version_and_current_projection(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:identity', 10, 20, 30, self::SOURCE, 'room', 'room:identity');
        $lengthEvidence = new Evidence('evidence:length:v1', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length:v1');
        $widthEvidence = new Evidence('evidence:width:v1', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width:v1');
        $repository->saveSourceModel([$room], [
            new Fact('fact:length:v1', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            new Fact('fact:width:v1', 10, 20, 30, self::SOURCE, $room->id, 'width', '3', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
        ], [$lengthEvidence, $widthEvidence]);
        $this->makeStageFiveCurrent($repository);

        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);
        $first = $service->derive(10, 20, 30);
        self::assertSame('15', $first['quantities']['floor_area']->amount ?? null);
        self::assertCount(1, $repository->quantities);
        $firstIdentity = array_values($repository->quantities)[0]->id;

        $replacementEvidence = new Evidence('evidence:length:v2', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length:v2');
        $repository->saveSourceModel([], [
            new Fact(
                'fact:length:v2', 10, 20, 30, self::SOURCE, $room->id, 'length', '5.0', 'm', 1.0,
                'document', 'confirmed', [$replacementEvidence->id], 2, 'fact:length:v1',
            ),
        ], [$replacementEvidence]);
        $this->makeStageFiveCurrent($repository);

        $second = $service->derive(10, 20, 30);

        self::assertSame('15', $second['quantities']['floor_area']->amount ?? null);
        self::assertCount(2, $repository->quantities);
        self::assertNotSame($firstIdentity, array_values($repository->quantities)[1]->id);
        self::assertCount(1, $repository->currentQuantities);
        self::assertSame(array_values($repository->quantities)[1]->id, array_values($repository->currentQuantities)[0]->id);
        self::assertSame(
            'fact:length:v2',
            $second['quantities']['floor_area']->formulaInputs['operands'][0]['fact_id'] ?? null,
        );

        $service->derive(10, 20, 30);
        self::assertCount(2, $repository->quantities);

        $this->makeStageFiveCurrent($repository, 'technology:v2', str_repeat('e', 64), 'rules:v2', str_repeat('f', 64));
        $service->derive(10, 20, 30);
        self::assertCount(3, $repository->quantities);
        self::assertCount(1, $repository->currentQuantities);
    }

    #[Test]
    public function exact_quantity_identity_collision_fails_closed(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:collision', 10, 20, 30, self::SOURCE, 'room', 'room:collision');
        $lengthEvidence = new Evidence('evidence:collision-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length');
        $widthEvidence = new Evidence('evidence:collision-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width');
        $repository->saveSourceModel([$room], [
            new Fact('fact:collision-length', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            new Fact('fact:collision-width', 10, 20, 30, self::SOURCE, $room->id, 'width', '3', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
        ], [$lengthEvidence, $widthEvidence]);
        $this->makeStageFiveCurrent($repository);
        (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);
        $persisted = array_values($repository->quantities)[0];
        $forged = new DerivedQuantity(
            id: $persisted->id,
            organizationId: $persisted->organizationId,
            projectId: $persisted->projectId,
            sessionId: $persisted->sessionId,
            sourceVersion: $persisted->sourceVersion,
            entityId: $persisted->entityId,
            formula: $persisted->formula,
            operands: $persisted->operands,
            value: '14',
            unit: $persisted->unit,
            roundingMode: $persisted->roundingMode,
            roundingScale: $persisted->roundingScale,
            evidenceIds: $persisted->evidenceIds,
            status: $persisted->status,
            formulaIdentity: $persisted->formulaIdentity,
            formulaVersion: $persisted->formulaVersion,
            roundingBoundary: $persisted->roundingBoundary,
            unitCompatibility: $persisted->unitCompatibility,
            snapshotIdentity: $persisted->snapshotIdentity,
            technologyDecisionId: $persisted->technologyDecisionId,
            logicalId: $persisted->logicalId,
            exactIdentity: $persisted->exactIdentity,
        );

        $this->expectException(\InvalidArgumentException::class);
        $repository->appendDerivedQuantities([$forged]);
    }

    #[Test]
    public function current_stage_five_package_is_projected_with_its_user_decision_and_persisted_once(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $entity = new Entity('roof:1', 10, 20, 30, self::SOURCE, 'quantity', 'roof:1');
        $room = new Entity('room:1', 10, 20, 30, self::SOURCE, 'room', 'room:1');
        $roof = new Entity('roof:geometry', 10, 20, 30, self::SOURCE, 'quantity', 'roof:geometry', ['semantic_type' => 'roof']);
        $facet = new Entity('facet:1', 10, 20, 30, self::SOURCE, 'quantity', 'facet:1', ['semantic_type' => 'roof_facet', 'roof_id' => 'roof:geometry']);
        $opening = new Entity('roof-opening:1', 10, 20, 30, self::SOURCE, 'quantity', 'roof-opening:1', ['semantic_type' => 'roof_opening', 'roof_id' => 'roof:geometry']);
        $evidence = new Evidence('evidence:roof-area', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'roof:area');
        $lengthEvidence = new Evidence('evidence:room-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length');
        $widthEvidence = new Evidence('evidence:room-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width');
        $facetAreaEvidence = new Evidence('evidence:facet-area', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'facet:area');
        $facetRiseEvidence = new Evidence('evidence:facet-rise', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'facet:rise');
        $facetRunEvidence = new Evidence('evidence:facet-run', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'facet:run');
        $openingAreaEvidence = new Evidence('evidence:opening-area', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'opening:area');
        $area = new Fact('fact:roof-area', 10, 20, 30, self::SOURCE, 'roof:1', 'roof_area', '88.125', 'm2', 1.0, 'document', 'confirmed', [$evidence->id]);
        $length = new Fact('fact:room-length', 10, 20, 30, self::SOURCE, 'room:1', 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]);
        $width = new Fact('fact:room-width', 10, 20, 30, self::SOURCE, 'room:1', 'width', '4', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]);
        $facetArea = new Fact('fact:facet-area', 10, 20, 30, self::SOURCE, 'facet:1', 'plan_area', '12', 'm2', 1.0, 'document', 'confirmed', [$facetAreaEvidence->id]);
        $facetRise = new Fact('fact:facet-rise', 10, 20, 30, self::SOURCE, 'facet:1', 'slope_rise', '3', 'm', 1.0, 'document', 'confirmed', [$facetRiseEvidence->id]);
        $facetRun = new Fact('fact:facet-run', 10, 20, 30, self::SOURCE, 'facet:1', 'slope_run', '4', 'm', 1.0, 'document', 'confirmed', [$facetRunEvidence->id]);
        $openingArea = new Fact('fact:opening-area', 10, 20, 30, self::SOURCE, 'roof-opening:1', 'area', '1.25', 'm2', 1.0, 'document', 'confirmed', [$openingAreaEvidence->id]);
        $repository->saveSourceModel(
            [$entity, $room, $roof, $facet, $opening],
            [$area, $length, $width, $facetArea, $facetRise, $facetRun, $openingArea],
            [$evidence, $lengthEvidence, $widthEvidence, $facetAreaEvidence, $facetRiseEvidence, $facetRunEvidence, $openingAreaEvidence],
        );

        $catalogHash = str_repeat('c', 64);
        $selectedSystem = new Fact(
            'fact:selected-roof-system', 10, 20, 30, self::SOURCE, 'roof:1', 'selected_roof_system',
            ['kind' => 'catalog_system', 'system_id' => 'metal-tile', 'catalog_version' => 'technology:v1', 'catalog_hash' => $catalogHash],
            null, 1.0, 'user_assumption', 'confirmed', [],
        );
        $decision = new Decision(
            'decision:roof-system', 10, 20, 30, self::SOURCE, 'fact', $selectedSystem->id,
            $selectedSystem->id, 'user', 'actor:7', 'Выбрана применимая система кровли', 1,
        );
        $repository->applyDecision($decision, $selectedSystem);
        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        self::assertTrue($repository->replaceTechnologyRecommendations(
            10, 20, 30, self::SOURCE, $token, 'technology:v1', $catalogHash, [], [],
        ));

        $package = new TechnologyWorkPackage(
            'package:fasteners', [], [], [], [], [[
                'id' => 'formula:fasteners',
                'expression' => 'roof_area × 1',
                'unit' => 'm2',
                'operands' => [[
                    'fact_id' => $area->id,
                    'fact_type' => 'roof_area',
                    'value' => '88.125',
                    'unit' => 'm2',
                    'version' => 1,
                    'status' => 'confirmed',
                ]],
                'resolved_value' => '88.125',
            ]], [], ['available' => false], [], [], ['rule_id' => 'fasteners'],
        );
        $finding = new CompletenessFinding(
            'fasteners', '1.0.0', str_repeat('d', 64), 'finding:fasteners', 1,
            'technology_required', 'proven_missing', 'warning', 'Требуется крепёж', 1.0,
            [$area->id], [$entity->id], ['roof_type'], ['status' => 'applicable'],
            ['allowed' => true], null, $package,
        );
        self::assertTrue($repository->replaceCompleteness(
            10, 20, 30, self::SOURCE, $token, 'technology:v1', $catalogHash,
            'rules:v1', str_repeat('e', 64), [$finding], [],
        ));

        $result = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);

        $quantity = $result['quantities']['quantity:technology_work_package:package:fasteners:formula:fasteners'] ?? null;
        self::assertNotNull($quantity);
        self::assertSame('88.13', $quantity->amount);
        self::assertSame($decision->id, $quantity->formulaInputs['technology_decision_id']);
        self::assertSame('20', $result['quantities']['floor_area']->amount ?? null);
        self::assertSame('13.75', $result['quantities']['roof_area']->amount ?? null);
        self::assertCount(3, $repository->quantities);
        self::assertSame([], $result['warnings']);
        self::assertSame($quantity->key, $result['context']['work_packages'][0]['quantities'][0]['key']);
        self::assertSame($decision->id, $result['context']['work_packages'][0]['technology_decision']['id']);

        $replayed = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);

        self::assertCount(3, $repository->quantities);
        self::assertSame(array_keys($result['quantities']), array_keys($replayed['quantities']));
        self::assertSame($result['context'], $replayed['context']);
    }

    private function makeStageFiveCurrent(
        InMemoryProjectModelRepository $repository,
        string $catalogVersion = 'technology:v1',
        string $catalogHash = '',
        string $ruleVersion = 'rules:v1',
        string $ruleHash = '',
    ): void {
        $catalogHash = $catalogHash !== '' ? $catalogHash : str_repeat('c', 64);
        $ruleHash = $ruleHash !== '' ? $ruleHash : str_repeat('d', 64);
        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        self::assertTrue($repository->replaceTechnologyRecommendations(
            10, 20, 30, self::SOURCE, $token, $catalogVersion, $catalogHash, [], [],
        ));
        self::assertTrue($repository->replaceCompleteness(
            10, 20, 30, self::SOURCE, $token, $catalogVersion, $catalogHash,
            $ruleVersion, $ruleHash, [], [],
        ));
    }
}
