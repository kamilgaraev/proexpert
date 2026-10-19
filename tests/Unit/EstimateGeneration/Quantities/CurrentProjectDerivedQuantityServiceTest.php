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
use App\BusinessModules\Addons\EstimateGeneration\Quantities\QuantityData;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\EstimateGeneration\InMemoryProjectModelRepository;

final class CurrentProjectDerivedQuantityServiceTest extends TestCase
{
    private const SOURCE = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function aggregate_planning_scope_preserves_document_quantity_versions_and_stale_runs_remove_their_active_projections(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $entity = new Entity('room:native', 10, 20, 30, self::SOURCE, 'room', 'room:native', ['semantic_type' => 'room']);
        $evidence = $this->nativeEvidence('evidence:area', 10, 20, 30, self::SOURCE, 'artifact:sheet', 'document',
            1, null, 'xlsx:sheet:Rooms!C2');
        $area = $this->nativeFact('fact:native-area', 10, 20, 30, self::SOURCE, $entity->id, 'area', '25.97', 'm2',
            0.0, 'document', 'confirmed', [$evidence->id]);
        $repository->saveSourceModel([$entity], [$area], [$evidence]);
        $descriptionVersion = 'sha256:'.str_repeat('c', 64);
        $description = new Entity('quantity:description', 10, 20, 30, $descriptionVersion, 'quantity', 'quantity:description');
        $repository->saveSourceModel([$description], [new Fact('fact:description', 10, 20, 30, $descriptionVersion,
            $description->id, 'evaluation_answer', 'Описание выбранного состава', null, 0.0, 'user_assumption', 'confirmed', [])], []);
        $aggregate = 'sha256:'.hash('sha256', self::SOURCE."\0".$descriptionVersion);
        $this->makeStageFiveCurrent($repository, sourceVersion: $aggregate);
        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);
        self::assertSame('25.97', $service->derive(10, 20, 30)['quantities']['floor_area']->amount);
        $rows = $repository->currentDerivedQuantities(10, 20, 30, self::SOURCE);
        self::assertCount(1, $rows);
        self::assertSame(self::SOURCE, $rows[0]->sourceVersion);
        self::assertSame([], $repository->currentDerivedQuantities(10, 20, 30, $aggregate));
        foreach ([null, str_repeat('f', 64)] as $expectedToken) {
            try {
                $repository->replaceDerivedQuantityProjection(10, 20, 30, $aggregate, $rows, [], $expectedToken);
                self::fail('Legacy cross-source or stale snapshot write was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
            self::assertCount(1, $repository->currentDerivedQuantities(10, 20, 30, self::SOURCE));
        }
        $geometryData = [
            'id' => 'quantity:independent-geometry', 'organizationId' => 10, 'projectId' => 20, 'sessionId' => 30,
            'sourceVersion' => self::SOURCE, 'entityId' => $rows[0]->entityId,
            'formula' => $rows[0]->formula, 'operands' => $rows[0]->operands, 'value' => $rows[0]->value,
            'unit' => $rows[0]->unit, 'roundingMode' => 'half_up', 'roundingScale' => 2,
            'evidenceIds' => $rows[0]->evidenceIds, 'status' => 'confirmed',
            'formulaIdentity' => $rows[0]->formulaIdentity, 'formulaVersion' => 'geometry-formulas:v2',
            'snapshotIdentity' => ['contract' => 'geometry-formulas:v2'], 'logicalId' => 'quantity:independent-geometry',
        ];
        $geometryIdentity = \App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\DerivedQuantityIdentity::for(new DerivedQuantity(...$geometryData));
        $geometry = new DerivedQuantity(...[...$geometryData, 'id' => 'quantityv:'.$geometryIdentity, 'exactIdentity' => $geometryIdentity]);
        $repository->replaceDerivedQuantityFormulaProjectionSet(10, 20, 30, 'geometry-formulas:v2', [$geometry]);
        self::assertCount(2, $repository->currentQuantities);
        $repository->removeProjection($area->id);
        self::assertSame([], $service->derive(10, 20, 30)['quantities']);
        self::assertSame([$geometry->id], array_column($repository->currentDerivedQuantities(10, 20, 30, self::SOURCE), 'id'));
        self::assertCount(1, $repository->derivedQuantityHistory(10, 20, 30, self::SOURCE, $rows[0]->logicalId));
    }

    #[Test]
    public function engineering_measurements_are_exact_per_entity_and_never_use_floor_area(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $entities = [];
        $facts = [];
        $evidence = [];
        foreach ([
            ['equipment', 'equipment_count', '3', 'count'],
            ['water_pipe', 'pipe_length', '12500', 'mm'],
            ['electrical_circuit', 'cable_length', '90', 'm'],
        ] as [$kind, $parameter, $value, $unit]) {
            $id = $kind.':zone-a';
            $entities[] = new Entity($id, 10, 20, 30, self::SOURCE, 'equipment', $id, ['properties' => ['semantic_type' => $kind]]);
            $proof = $this->nativeEvidence('evidence:'.$kind, 10, 20, 30, self::SOURCE, 'artifact:spec', 'document', 1, null, 'xlsx:sheet:Spec!C2');
            $evidence[] = $proof;
            $facts[] = $this->nativeFact('fact:'.$kind, 10, 20, 30, self::SOURCE, $id, $parameter, $value, $unit, 0.0, 'document', 'confirmed', [$proof->id]);
        }
        $repository->saveSourceModel($entities, $facts, $evidence);
        $this->makeStageFiveCurrent($repository);
        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);
        $result = $service->derive(10, 20, 30);
        self::assertSame('3', $result['quantities']['equipment.count']->amount);
        self::assertSame('12.5', $result['quantities']['plumbing.pipe']->amount);
        self::assertSame('90', $result['quantities']['electrical.main_cable']->amount);
        $mapper = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
        self::assertSame('3', $mapper->mapForEntity('equipment.install', $result['quantities'], 'equipment:zone-a')?->amount);
        self::assertNull($mapper->mapForEntity('equipment.install', $result['quantities'], 'equipment:zone-b'));
        self::assertNull($mapper->mapForEntity('electrical.trays', $result['quantities'], 'electrical_circuit:zone-a'));
        self::assertNull($mapper->mapForEntity('electrical.grounding', $result['quantities'], 'electrical_circuit:zone-a'));
        self::assertSame([], $result['warnings']);
    }

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
        self::assertSame([], $result['context']['room_floor_entity_ids']);
    }

    #[Test]
    public function mixed_floor_formulas_preserve_each_room_and_exclude_a_whole_building_from_room_work(): void
    {
        foreach ([false, true] as $withBuilding) {
            $repository = new InMemoryProjectModelRepository;
            $entities = [new Entity('room:area', 10, 20, 30, self::SOURCE, 'room', 'room:area'),
                new Entity('room:dimensions', 10, 20, 30, self::SOURCE, 'room', 'room:dimensions')];
            $measurements = [['room:area', 'area', '4', 'm2'], ['room:dimensions', 'length', '2', 'm'],
                ['room:dimensions', 'width', '3', 'm']];
            if ($withBuilding) {
                $entities[] = new Entity('room:building-floor', 10, 20, 30, self::SOURCE, 'room', 'building.area.total',
                    ['semantic_type' => 'room', 'document_role' => 'building_floor']);
                $measurements[] = ['room:building-floor', 'area', '40', 'm2'];
            }
            $facts = $evidence = [];
            foreach ($measurements as [$entity, $parameter, $amount, $unit]) {
                $id = $entity.':'.$parameter;
                $proof = $this->nativeEvidence('evidence:'.$id, 10, 20, 30, self::SOURCE, 'artifact:plan', 'document', 1, null, $id);
                $evidence[] = $proof;
                $facts[] = $this->nativeFact('fact:'.$id, 10, 20, 30, self::SOURCE, $entity, $parameter,
                    $amount, $unit, 1.0, 'document', 'confirmed', [$proof->id]);
            }
            $repository->saveSourceModel($entities, $facts, $evidence);
            $this->makeStageFiveCurrent($repository);
            $result = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);
            if ($withBuilding) {
                self::assertSame('40', $result['quantities']['floor_area']->amount);
                $mapper = new \App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityMapper;
                self::assertSame('40', $mapper->map('office.ceiling', $result['quantities'])?->amount);
                self::assertNull($mapper->map('rough.floor', $result['quantities']));
                self::assertSame([], $result['warnings']);
            } else {
                self::assertArrayNotHasKey('floor_area', $result['quantities']);
                self::assertContains('canonical_quantity_aggregate_unresolved', array_column($result['warnings'], 'code'));
            }
            self::assertCount($withBuilding ? 3 : 2, $result['quantities']);
            self::assertSame(['room:area', 'room:dimensions'], $result['context']['room_floor_entity_ids']);
            $items = [];
            foreach (['rough.floor', 'finish.floor'] as $work) {
                $items[] = ['key' => 'catalog:'.$work, 'item_type' => 'priced_work', 'quantity' => null,
                    'metadata' => ['generation_source' => 'qualitative_work_catalog', 'quantity_source' => 'unknown',
                        'composition_work_key' => $work, 'quantity_key' => $work]];
            }
            $draft = ['local_estimates' => [['sections' => [['work_items' => $items]]]]];
            $expanded = (new \App\BusinessModules\Addons\EstimateGeneration\Quantities\RoomFloorWorkItemExpander)->expand(
                $draft, $result['quantities'], 128, $result['context']['room_floor_entity_ids']);
            $expandedItems = $expanded['local_estimates'][0]['sections'][0]['work_items'];
            self::assertCount(4, $expandedItems);
            $amounts = [];
            foreach ($expandedItems as $item) {
                $quantity = (new \App\BusinessModules\Addons\EstimateGeneration\Quantities\WorkItemQuantityResolver)->resolve($item, $result['quantities']);
                self::assertNotNull($quantity);
                $amounts[$item['metadata']['composition_work_key']][$item['entity_id']] = $quantity->amount;
            }
            self::assertSame(['room:area' => '4', 'room:dimensions' => '6'], $amounts['rough.floor']);
            self::assertSame($amounts['rough.floor'], $amounts['finish.floor']);
        }
    }

    #[Test]
    public function an_unresolved_room_blocks_a_partial_alias_of_another_floor_formula(): void
    {
        foreach (['none', 'confirmed', 'unresolved', 'conflicting', 'partially_confirmed'] as $buildingState) {
            $repository = new InMemoryProjectModelRepository;
            $entities = [new Entity('room:area', 10, 20, 30, self::SOURCE, 'room', 'room:area'),
                new Entity('room:missing-width', 10, 20, 30, self::SOURCE, 'room', 'room:missing-width')];
            $measurements = [['room:area', 'area', '4', 'm2'], ['room:missing-width', 'length', '2', 'm']];
            if ($buildingState !== 'none') {
                $entities[] = new Entity('room:building-floor', 10, 20, 30, self::SOURCE, 'room', 'building.area.total',
                    ['document_role' => 'building_floor']);
                if ($buildingState !== 'unresolved') {
                    $measurements[] = ['room:building-floor', 'area', '40', 'm2'];
                }
                if (in_array($buildingState, ['conflicting', 'partially_confirmed'], true)) {
                    $entities[] = new Entity('room:building-other', 10, 20, 30, self::SOURCE, 'room', 'building.other.total',
                        ['document_role' => 'building_floor']);
                    if ($buildingState === 'conflicting') {
                        $measurements[] = ['room:building-other', 'area', '41', 'm2'];
                    }
                }
            }
            $facts = $evidence = [];
            foreach ($measurements as [$entity, $parameter, $amount, $unit]) {
                $id = $entity.':'.$parameter;
                $proof = $this->nativeEvidence('evidence:'.$id, 10, 20, 30, self::SOURCE, 'artifact:plan', 'document', 1, null, $id);
                $evidence[] = $proof;
                $facts[] = $this->nativeFact('fact:'.$id, 10, 20, 30, self::SOURCE, $entity, $parameter,
                    $amount, $unit, 1.0, 'document', 'confirmed', [$proof->id]);
            }
            $repository->saveSourceModel($entities, $facts, $evidence);
            $this->makeStageFiveCurrent($repository);
            $result = (new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory))->derive(10, 20, 30);
            if ($buildingState === 'confirmed') {
                self::assertSame('40', $result['quantities']['floor_area']->amount);
                self::assertNotContains('canonical_quantity_aggregate_unresolved', array_column($result['warnings'], 'code'));
            } else {
                self::assertArrayNotHasKey('floor_area', $result['quantities']);
            }
            self::assertCount(match ($buildingState) {
                'conflicting' => 3, 'confirmed', 'partially_confirmed' => 2, default => 1,
            }, $result['quantities']);
            self::assertSame(['room:area', 'room:missing-width'], $result['context']['room_floor_entity_ids']);
            self::assertContains('canonical_quantity_unresolved', array_column($result['warnings'], 'code'));
        }
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

    #[Test]
    public function foundation_preview_requires_its_own_height_and_never_invents_a_global_sum(): void
    {
        $repository = new InMemoryProjectModelRepository;
        $entities = [];
        foreach (['beam' => 'foundation_beam', 'slab' => 'foundation_slab', 'site' => 'site'] as $key => $type) {
            $entities[] = new Entity($key, 10, 20, 30, self::SOURCE, 'quantity', $key, ['semantic_type' => $type]);
        }
        $facts = $proofs = [];
        foreach ([['beam', 'length', '10', 'm'], ['beam', 'width', '250', 'mm'],
            ['slab', 'area', '40', 'm2'], ['slab', 'thickness', '80', 'mm'], ['site', 'depth', '0.5', 'm']] as [$entity, $parameter, $value, $unit]) {
            $id = $entity.':'.$parameter;
            $proofs[] = $this->nativeEvidence('evidence:'.$id, 10, 20, 30, self::SOURCE, 'artifact:plan', 'cad', 1, null, 'section:'.$id);
            $facts[] = $this->nativeFact($id, 10, 20, 30, self::SOURCE, $entity, $parameter, $value, $unit, 1.0, 'document', 'confirmed', ['evidence:'.$id]);
        }
        $repository->saveSourceModel($entities, $facts, $proofs);
        $service = new CurrentProjectDerivedQuantityService($repository, new DerivedQuantityFactory);
        $missing = $service->previewInput(10, 20, 30);
        self::assertArrayNotHasKey('foundation_volume', $missing['quantities']);
        $beamWarning = array_values(array_filter($missing['warnings'], static fn (array $row): bool => ($row['formula'] ?? null) === 'foundation_beam_volume'));
        self::assertCount(1, $beamWarning);
        self::assertSame('height', $beamWarning[0]['inputs'][0]['operand']);
        self::assertSame('operand_missing', $beamWarning[0]['inputs'][0]['code']);
        self::assertSame(['3.2'], array_values(array_map(static fn (QuantityData $quantity): string => $quantity->amount, $missing['quantities'])));

        $proof = $this->nativeEvidence('evidence:beam-height', 10, 20, 30, self::SOURCE, 'artifact:section', 'cad', 2, null, 'section:height');
        $height = $this->nativeFact('beam-height', 10, 20, 30, self::SOURCE, 'beam', 'height', '400', 'mm', 1.0, 'document', 'confirmed', [$proof->id]);
        $repository->saveSourceModel([], [$height], [$proof]);
        $complete = $service->previewInput(10, 20, 30);
        self::assertArrayNotHasKey('foundation_volume', $complete['quantities']);
        $amounts = array_map(static fn (QuantityData $quantity): string => $quantity->amount, $complete['quantities']);
        sort($amounts);
        self::assertSame(['1', '3.2'], $amounts);
        self::assertContains('canonical_quantity_aggregate_unresolved', array_column($complete['warnings'], 'code'));
        self::assertSame([], $repository->quantities);
        self::assertSame([], $repository->currentQuantities);
    }

    private function makeStageFiveCurrent(
        InMemoryProjectModelRepository $repository,
        string $catalogVersion = 'technology:v1',
        string $catalogHash = '',
        string $ruleVersion = 'rules:v1',
        string $ruleHash = '',
        string $sourceVersion = self::SOURCE,
    ): void {
        $catalogHash = $catalogHash !== '' ? $catalogHash : str_repeat('c', 64);
        $ruleHash = $ruleHash !== '' ? $ruleHash : str_repeat('d', 64);
        $token = $repository->snapshotForPlanning(10, 20, 30, 10001)['token'];
        self::assertTrue($repository->replaceTechnologyRecommendations(
            10, 20, 30, $sourceVersion, $token, $catalogVersion, $catalogHash, [], [],
        ));
        self::assertTrue($repository->replaceCompleteness(
            10, 20, 30, $sourceVersion, $token, $catalogVersion, $catalogHash,
            $ruleVersion, $ruleHash, [], [],
        ));
    }
}
