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
    public function accepted_whole_building_area_is_a_direct_floor_quantity_without_a_synthetic_takeoff(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $buildingFloor = new Entity(
            'room:building-floor',
            10,
            20,
            30,
            self::SOURCE,
            'room',
            'room:building-floor',
            ['area_m2' => 72.19, 'document_role' => 'building_floor'],
        );
        $evidence = $this->nativeEvidence(
            'evidence:building-area',
            10,
            20,
            30,
            self::SOURCE,
            'artifact:general-data',
            'document',
            4,
            null,
            'building_area_total',
            'native_numeric_parser',
        );
        $repository->saveSourceModel([$buildingFloor], [
            $this->nativeFact(
                'fact:building-area',
                10,
                20,
                30,
                self::SOURCE,
                $buildingFloor->id,
                'area',
                '72.19',
                'm2',
                1.0,
                'document',
                'confirmed',
                [$evidence->id],
            ),
        ], [$evidence]);
        $this->makeStageFiveCurrent($repository);

        $result = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertSame('72.19', $result['quantities']['floor_area']->amount ?? null);
        self::assertSame('direct_floor_area', $result['quantities']['floor_area']->formulaKey ?? null);
        self::assertSame('fact:building-area', $result['quantities']['floor_area']->formulaInputs['operands'][0]['fact_id'] ?? null);
    }

    #[Test]
    public function simulation_uses_proposed_decimal_value_and_never_persists_projection(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:simulation', 10, 20, 30, self::SOURCE, 'room', 'room:simulation');
        $lengthEvidence = $this->nativeEvidence('evidence:simulation-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length');
        $widthEvidence = $this->nativeEvidence('evidence:simulation-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width');
        $repository->saveSourceModel([$room], [
            $this->nativeFact('fact:simulation-length', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            $this->nativeFact('fact:simulation-width', 10, 20, 30, self::SOURCE, $room->id, 'width', '10', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
        ], [$lengthEvidence, $widthEvidence]);
        $this->makeStageFiveCurrent($repository);
        $capture = $repository->snapshotForPlanning(10, 20, 30, 100);
        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);

        $amounts = [];
        foreach (['5', '6', '7'] as $value) {
            $userProof = new Evidence('evidence:simulation-user:'.$value, 10, 20, 30, self::SOURCE, 'input:30', 'user_input', nativeReference: 'input_payload.simulation');
            $facts = array_map(static fn (Fact $fact): Fact => $fact->id === 'fact:simulation-length'
                ? new Fact(
                    $fact->id, $fact->organizationId, $fact->projectId, $fact->sessionId, $fact->sourceVersion,
                    $fact->entityId, $fact->type, $value, $fact->unit, 0.0, 'user_input',
                    'confirmed', [$userProof->id], $fact->version + 1, $fact->supersedesFactId,
                )
                : $fact, $capture['snapshot']->facts);
            $projection = $service->simulate(
                new \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot(
                    $capture['snapshot']->entities,
                    $facts,
                    [...$capture['snapshot']->evidence, $userProof],
                    $capture['snapshot']->conflicts,
                ),
                $capture['token'],
                $repository->currentTechnologyRecommendations(10, 20, 30),
                $repository->currentCompleteness(10, 20, 30),
                [new Decision('decision:simulation:'.$value, 10, 20, 30, self::SOURCE, 'fact', 'fact:simulation-length',
                    'fact:simulation-length', 'user', '7', 'Пользователь рассматривает введённую длину', 2, [$userProof->id])],
            );
            $amounts[] = $projection['quantities']['floor_area']->amount ?? null;
        }

        self::assertSame(['50', '60', '70'], $amounts);
        self::assertSame([], $repository->quantities);
        self::assertSame([], $repository->currentQuantities);
    }

    #[Test]
    public function production_projection_requires_proven_empty_opening_coverage_and_keeps_partial_opening_unresolved(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $wall = new Entity('wall:1', 10, 20, 30, self::SOURCE, 'wall', 'wall:1');
        $lengthEvidence = $this->nativeEvidence('evidence:wall-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'wall:1');
        $heightEvidence = $this->nativeEvidence('evidence:wall-height', 10, 20, 30, self::SOURCE, 'artifact:section', 'cad', 2, null, 'wall:1');
        $repository->saveSourceModel([$wall], [
            $this->nativeFact('fact:wall-length', 10, 20, 30, self::SOURCE, $wall->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            $this->nativeFact('fact:wall-height', 10, 20, 30, self::SOURCE, $wall->id, 'height', '3', 'm', 1.0, 'document', 'confirmed', [$heightEvidence->id]),
        ], [$lengthEvidence, $heightEvidence]);
        $this->makeStageFiveCurrent($repository);

        $withoutOpenings = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertArrayNotHasKey('net_wall_area', $withoutOpenings['quantities']);
        self::assertSame('geometry_coverage_unknown', $withoutOpenings['warnings'][0]['inputs'][0]['code'] ?? null);

        $coverageEvidence = $this->nativeEvidence(
            'evidence:wall-openings-coverage', 10, 20, 30, self::SOURCE,
            'artifact:plan', 'cad', 1, null, 'wall:1:openings',
        );
        $repository->saveSourceModel([], [
            $this->nativeFact(
                'fact:wall-openings-coverage', 10, 20, 30, self::SOURCE, $wall->id, 'geometry_coverage',
                [
                    'relation' => 'wall_openings',
                    'status' => 'covered_empty',
                    'entity_count' => 0,
                    'representation' => [
                        'type' => 'cad_geometry',
                        'id' => 'representation:wall:1',
                        'source_artifact_id' => 'artifact:plan',
                        'source_version' => self::SOURCE,
                    ],
                ],
                null, 1.0, 'document', 'confirmed', [$coverageEvidence->id],
            ),
        ], [$coverageEvidence]);
        $this->makeStageFiveCurrent($repository);

        $unconfirmed = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);
        self::assertArrayNotHasKey('net_wall_area', $unconfirmed['quantities']);
        self::assertSame('geometry_coverage_blocked', $unconfirmed['warnings'][0]['inputs'][0]['code'] ?? null);
        $this->confirmCoverage($repository, 'fact:wall-openings-coverage');
        $this->makeStageFiveCurrent($repository);
        $coveredEmpty = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertSame('15', $coveredEmpty['quantities']['net_wall_area']->amount ?? null);
        self::assertSame([], $coveredEmpty['warnings']);

        $partial = new Entity('opening:partial', 10, 20, 30, self::SOURCE, 'opening', 'opening:partial', [
            'wall_id' => $wall->id,
        ]);
        $widthEvidence = $this->nativeEvidence('evidence:opening-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'opening:partial');
        $repository->saveSourceModel([$partial], [
            $this->nativeFact('fact:opening-width', 10, 20, 30, self::SOURCE, $partial->id, 'width', '2', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
            $this->nativeFact(
                'fact:wall-openings-coverage:v2', 10, 20, 30, self::SOURCE, $wall->id, 'geometry_coverage',
                [
                    'relation' => 'wall_openings',
                    'status' => 'covered_with_entities',
                    'entity_count' => 1,
                    'representation' => [
                        'type' => 'cad_geometry',
                        'id' => 'representation:wall:1:v2',
                        'source_artifact_id' => 'artifact:plan',
                        'source_version' => self::SOURCE,
                    ],
                ],
                null, 1.0, 'document', 'confirmed', [$coverageEvidence->id], 2,
                'fact:wall-openings-coverage',
            ),
        ], [$widthEvidence]);
        $this->confirmCoverage($repository, 'fact:wall-openings-coverage:v2');
        $this->makeStageFiveCurrent($repository);

        $withPartialOpening = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertArrayNotHasKey('net_wall_area', $withPartialOpening['quantities']);
        self::assertSame('canonical_quantity_unresolved', $withPartialOpening['warnings'][0]['code'] ?? null);
        self::assertSame('geometry_operand_missing', $withPartialOpening['warnings'][0]['inputs'][0]['code'] ?? null);
        self::assertSame('opening:partial', $withPartialOpening['warnings'][0]['inputs'][0]['entity_id'] ?? null);
        self::assertSame('height', $withPartialOpening['warnings'][0]['inputs'][0]['missing_operand'] ?? null);
        self::assertSame('opening:partial', $withPartialOpening['warnings'][0]['inputs'][0]['source_locator']['native_reference'] ?? null);
        self::assertSame([], $repository->currentQuantities);

        $heightEvidence = $this->nativeEvidence('evidence:opening-height', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'opening:partial');
        $repository->saveSourceModel([], [
            $this->nativeFact('fact:opening-height', 10, 20, 30, self::SOURCE, $partial->id, 'height', '2', 'm', 1.0, 'document', 'confirmed', [$heightEvidence->id]),
        ], [$heightEvidence]);
        $this->makeStageFiveCurrent($repository);

        $recovered = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))
            ->derive(10, 20, 30);

        self::assertSame('11', $recovered['quantities']['net_wall_area']->amount ?? null);
        self::assertCount(1, $repository->currentQuantities);
        $historyCount = count($repository->quantities);
        (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);
        self::assertCount($historyCount, $repository->quantities);
    }

    #[Test]
    public function meaningful_operand_replacement_creates_a_new_exact_quantity_version_and_current_projection(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:identity', 10, 20, 30, self::SOURCE, 'room', 'room:identity');
        $lengthEvidence = $this->nativeEvidence('evidence:length:v1', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length:v1');
        $widthEvidence = $this->nativeEvidence('evidence:width:v1', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width:v1');
        $repository->saveSourceModel([$room], [
            $this->nativeFact('fact:length:v1', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            $this->nativeFact('fact:width:v1', 10, 20, 30, self::SOURCE, $room->id, 'width', '3', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
        ], [$lengthEvidence, $widthEvidence]);
        $this->makeStageFiveCurrent($repository);

        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);
        $first = $service->derive(10, 20, 30);
        self::assertSame('15', $first['quantities']['floor_area']->amount ?? null);
        self::assertCount(1, $repository->quantities);
        $firstIdentity = array_values($repository->quantities)[0]->id;

        $replacementEvidence = $this->nativeEvidence('evidence:length:v2', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length:v2');
        $repository->saveSourceModel([], [
            $this->nativeFact(
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
    public function stale_stage_five_projection_deactivates_current_quantities_without_removing_history(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:stale-stage-five', 10, 20, 30, self::SOURCE, 'room', 'room:stale-stage-five');
        $lengthEvidence = $this->nativeEvidence('evidence:stale-length:v1', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:stale:length:v1');
        $widthEvidence = $this->nativeEvidence('evidence:stale-width:v1', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:stale:width:v1');
        $repository->saveSourceModel([$room], [
            $this->nativeFact('fact:stale-length:v1', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            $this->nativeFact('fact:stale-width:v1', 10, 20, 30, self::SOURCE, $room->id, 'width', '3', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
        ], [$lengthEvidence, $widthEvidence]);
        $this->makeStageFiveCurrent($repository);

        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);
        $service->derive(10, 20, 30);

        self::assertCount(1, $repository->currentQuantities);
        self::assertCount(1, $repository->quantities);

        $replacementEvidence = $this->nativeEvidence('evidence:stale-length:v2', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:stale:length:v2');
        $repository->saveSourceModel([], [
            $this->nativeFact(
                'fact:stale-length:v2', 10, 20, 30, self::SOURCE, $room->id, 'length', '6', 'm', 1.0,
                'document', 'confirmed', [$replacementEvidence->id], 2, 'fact:stale-length:v1',
            ),
        ], [$replacementEvidence]);

        $blocked = $service->derive(10, 20, 30);

        self::assertSame('stage5_projection_not_current', $blocked['warnings'][0]['code'] ?? null);
        self::assertSame([], $repository->currentQuantities);
        self::assertCount(1, $repository->quantities);
    }

    #[Test]
    public function exact_quantity_identity_collision_fails_closed(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $room = new Entity('room:collision', 10, 20, 30, self::SOURCE, 'room', 'room:collision');
        $lengthEvidence = $this->nativeEvidence('evidence:collision-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length');
        $widthEvidence = $this->nativeEvidence('evidence:collision-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width');
        $repository->saveSourceModel([$room], [
            $this->nativeFact('fact:collision-length', 10, 20, 30, self::SOURCE, $room->id, 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]),
            $this->nativeFact('fact:collision-width', 10, 20, 30, self::SOURCE, $room->id, 'width', '3', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]),
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
        $evidence = $this->nativeEvidence('evidence:roof-area', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'roof:area');
        $lengthEvidence = $this->nativeEvidence('evidence:room-length', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:length');
        $widthEvidence = $this->nativeEvidence('evidence:room-width', 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'room:width');
        $facetAreaEvidence = $this->nativeEvidence('evidence:facet-area', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'facet:area');
        $facetRiseEvidence = $this->nativeEvidence('evidence:facet-rise', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'facet:rise');
        $facetRunEvidence = $this->nativeEvidence('evidence:facet-run', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'facet:run');
        $openingAreaEvidence = $this->nativeEvidence('evidence:opening-area', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'opening:area');
        $roofCoverageEvidence = $this->nativeEvidence('evidence:roof-coverage', 10, 20, 30, self::SOURCE, 'artifact:roof', 'cad', 1, null, 'roof:geometry:coverage');
        $area = $this->nativeFact('fact:roof-area', 10, 20, 30, self::SOURCE, 'roof:1', 'roof_area', '88.125', 'm2', 1.0, 'document', 'confirmed', [$evidence->id]);
        $length = $this->nativeFact('fact:room-length', 10, 20, 30, self::SOURCE, 'room:1', 'length', '5', 'm', 1.0, 'document', 'confirmed', [$lengthEvidence->id]);
        $width = $this->nativeFact('fact:room-width', 10, 20, 30, self::SOURCE, 'room:1', 'width', '4', 'm', 1.0, 'document', 'confirmed', [$widthEvidence->id]);
        $facetArea = $this->nativeFact('fact:facet-area', 10, 20, 30, self::SOURCE, 'facet:1', 'plan_area', '12', 'm2', 1.0, 'document', 'confirmed', [$facetAreaEvidence->id]);
        $facetRise = $this->nativeFact('fact:facet-rise', 10, 20, 30, self::SOURCE, 'facet:1', 'slope_rise', '3', 'm', 1.0, 'document', 'confirmed', [$facetRiseEvidence->id]);
        $facetRun = $this->nativeFact('fact:facet-run', 10, 20, 30, self::SOURCE, 'facet:1', 'slope_run', '4', 'm', 1.0, 'document', 'confirmed', [$facetRunEvidence->id]);
        $openingArea = $this->nativeFact('fact:opening-area', 10, 20, 30, self::SOURCE, 'roof-opening:1', 'area', '1.25', 'm2', 1.0, 'document', 'confirmed', [$openingAreaEvidence->id]);
        $roofFacetCoverage = $this->nativeFact(
            'fact:roof-facet-coverage', 10, 20, 30, self::SOURCE, 'roof:geometry', 'geometry_coverage_roof_facets',
            [
                'relation' => 'roof_facets',
                'status' => 'covered_with_entities',
                'entity_count' => 1,
                'representation' => [
                    'type' => 'cad_geometry',
                    'id' => 'representation:roof:geometry',
                    'source_artifact_id' => 'artifact:roof',
                    'source_version' => self::SOURCE,
                ],
            ],
            null, 1.0, 'document', 'confirmed', [$roofCoverageEvidence->id],
        );
        $roofOpeningCoverage = $this->nativeFact(
            'fact:roof-opening-coverage', 10, 20, 30, self::SOURCE, 'roof:geometry', 'geometry_coverage_roof_openings',
            [
                'relation' => 'roof_openings',
                'status' => 'covered_with_entities',
                'entity_count' => 1,
                'representation' => [
                    'type' => 'cad_geometry',
                    'id' => 'representation:roof:openings',
                    'source_artifact_id' => 'artifact:roof',
                    'source_version' => self::SOURCE,
                ],
            ],
            null, 1.0, 'document', 'confirmed', [$roofCoverageEvidence->id],
        );
        $repository->saveSourceModel(
            [$entity, $room, $roof, $facet, $opening],
            [$area, $length, $width, $facetArea, $facetRise, $facetRun, $openingArea, $roofFacetCoverage, $roofOpeningCoverage],
            [$evidence, $lengthEvidence, $widthEvidence, $facetAreaEvidence, $facetRiseEvidence, $facetRunEvidence, $openingAreaEvidence, $roofCoverageEvidence],
        );

        $this->confirmCoverage($repository, 'fact:roof-facet-coverage');
        $this->confirmCoverage($repository, 'fact:roof-opening-coverage');
        $catalogHash = str_repeat('c', 64);
        $selectedSystem = $this->nativeFact(
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

    private function confirmCoverage(InMemoryProjectModelRepository $repository, string $factId): void
    {
        $original = $repository->fact(10, 20, 30, $factId);
        self::assertInstanceOf(Fact::class, $original);
        $current = array_values(array_filter($repository->currentFacts(10, 20, 30, $original->entityId),
            static fn (Fact $fact): bool => $fact->type === $original->type))[0] ?? $original;
        $proof = $this->nativeEvidence($factId.':user-proof', 10, 20, 30, self::SOURCE, 'input:coverage', 'user_input', nativeReference: 'input:coverage');
        $selected = $this->nativeFact($factId.':reviewed', 10, 20, 30, self::SOURCE, $original->entityId, $original->type,
            $original->value, null, 0, 'user_input', 'confirmed', [$proof->id], max($current->version, $original->version) + 1, $current->id);
        $repository->saveSourceModel([], [$selected], [$proof]);
        $repository->applyDecision(new Decision($factId.':user-decision', 10, 20, 30, self::SOURCE, 'fact', $current->id,
            $selected->id, 'user', '7', 'Проверен полный состав геометрии', $selected->version, [$proof->id]), $selected);
    }

    /** Explicit certified document fixtures. Proposed user values must use their own decision and evidence. */
    private function nativeFact(string $id, int $organizationId, int $projectId, int $sessionId, string $sourceVersion,
        string $entityId, string $type, mixed $value, ?string $unit, float $confidence, string $origin, string $status,
        array $evidenceIds, int $version = 1, ?string $supersedesFactId = null): Fact
    {
        $proofs = [];
        if ($origin === 'document' && is_numeric($value)) {
            foreach ($evidenceIds as $evidenceId) {
                $proofs[$evidenceId] = ['entity_id' => $entityId, 'source_version' => $sourceVersion,
                    'parameter' => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::parameter($type),
                    'measurement' => \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\FactVocabulary::measurementSignature((string) $value, $unit),
                    'evidence_fingerprint' => hash('sha256', $evidenceId)];
            }
        }

        return new Fact($id, $organizationId, $projectId, $sessionId, $sourceVersion, $entityId, $type, $value, $unit,
            $confidence, $origin, $status, $evidenceIds, $version, $supersedesFactId, $proofs);
    }

    private function nativeEvidence(string $id, int $organizationId, int $projectId, int $sessionId, string $sourceVersion,
        string $sourceArtifactId, string $sourceType, ?int $page = null, ?array $region = null, ?string $nativeReference = null,
        ?string $producerName = null): Evidence
    {
        $document = in_array($sourceType, ['cad', 'document'], true);

        return new Evidence($id, $organizationId, $projectId, $sessionId, $sourceVersion, $sourceArtifactId,
            $document ? 'document' : $sourceType, $page, $region, $nativeReference,
            $document ? 'native_numeric_parser' : $producerName, fingerprint: hash('sha256', $id));
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
