<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Quantities;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Decision;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Evidence;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\DerivedQuantityFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DerivedQuantityFactoryTest extends TestCase
{
    private const SOURCE_VERSION = 'sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    #[Test]
    public function floor_area_is_exact_deterministic_and_preserves_operand_lineage(): void
    {
        $snapshot = $this->snapshot(
            [$this->entity('room:1', 'room')],
            [
                $this->fact('length', 'room:1', 'length', '5000', 'mm', ['evidence:length']),
                $this->fact('width', 'room:1', 'width', '3.25', 'm', ['evidence:width']),
            ],
            [$this->evidence('evidence:length', 'cad:length:1'), $this->evidence('evidence:width', 'pdf:span:7')],
        );

        $result = (new DerivedQuantityFactory)->derive($snapshot, [], $this->request(
            'floor_area',
            'room:1',
            ['length' => 'length', 'width' => 'width'],
        ));

        self::assertTrue($result->isReady());
        self::assertSame('16.25', $result->quantity?->value);
        self::assertSame('m2', $result->quantity?->unit);
        self::assertSame('floor_area', $result->quantity?->formulaIdentity);
        self::assertSame('1', $result->quantity?->formulaVersion);
        self::assertSame(['length', 'width'], array_column($result->quantity?->operands ?? [], 'role'));
        self::assertSame('5000', $result->quantity?->operands[0]['source_value'] ?? null);
        self::assertSame('mm', $result->quantity?->operands[0]['source_unit'] ?? null);
        self::assertSame('5', $result->quantity?->operands[0]['value'] ?? null);
        self::assertSame('cad:length:1', $result->quantity?->operands[0]['evidence'][0]['native_reference'] ?? null);
        self::assertSame([], $result->unresolvedInputs);
    }

    #[Test]
    public function wall_area_subtracts_scoped_openings_once_and_rejects_over_subtraction(): void
    {
        $entities = [
            $this->entity('wall:1', 'wall', ['room_id' => 'room:1', 'floor_id' => 'floor:1']),
            $this->entity('opening:1', 'opening', ['wall_id' => 'wall:1', 'geometry_identity' => 'geom:opening:1']),
        ];
        $facts = [
            $this->fact('wall-length', 'wall:1', 'length', '5', 'm', ['evidence:wall-length']),
            $this->fact('wall-height', 'wall:1', 'height', '3', 'm', ['evidence:wall-height']),
            $this->fact('opening-width', 'opening:1', 'width', '1', 'm', ['evidence:opening-width']),
            $this->fact('opening-height', 'opening:1', 'height', '2', 'm', ['evidence:opening-height']),
        ];
        $evidence = array_map(fn (Fact $fact): Evidence => $this->evidence($fact->evidenceIds[0], 'native:'.$fact->id), $facts);
        $request = $this->request('wall_net_area', 'wall:1', [
            'wall_length' => 'wall-length',
            'wall_height' => 'wall-height',
            'opening_widths' => ['opening-width', 'opening-width'],
            'opening_heights' => ['opening-height', 'opening-height'],
        ]);

        $duplicate = (new DerivedQuantityFactory)->derive($this->snapshot($entities, $facts, $evidence), [], $request);
        self::assertFalse($duplicate->isReady());
        self::assertSame('duplicate_operand', $duplicate->unresolvedInputs[0]['code'] ?? null);

        $request['operands']['opening_widths'] = ['opening-width'];
        $request['operands']['opening_heights'] = ['opening-height'];
        $ready = (new DerivedQuantityFactory)->derive($this->snapshot($entities, $facts, $evidence), [], $request);
        self::assertSame('13', $ready->quantity?->value);

        $facts[2] = $this->fact('opening-width', 'opening:1', 'width', '10', 'm', ['evidence:opening-width']);
        $blocked = (new DerivedQuantityFactory)->derive($this->snapshot($entities, $facts, $evidence), [], $request);
        self::assertFalse($blocked->isReady());
        self::assertSame('negative_quantity', $blocked->unresolvedInputs[0]['code'] ?? null);
    }

    #[Test]
    public function sloped_roof_keeps_facets_inside_one_roof_and_uses_no_float(): void
    {
        $entities = [
            $this->entity('roof:1', 'roof'),
            $this->entity('facet:1', 'roof_facet', ['roof_id' => 'roof:1']),
            $this->entity('facet:2', 'roof_facet', ['roof_id' => 'roof:1']),
        ];
        $facts = [];
        $evidence = [];
        foreach ([1, 2] as $index) {
            foreach ([['plan-area', 'plan_area', '12', 'm2'], ['rise', 'slope_rise', '3', 'm'], ['run', 'slope_run', '4', 'm']] as [$suffix, $type, $value, $unit]) {
                $id = "facet-$index-$suffix";
                $facts[] = $this->fact($id, "facet:$index", $type, $value, $unit, ["evidence:$id"]);
                $evidence[] = $this->evidence("evidence:$id", "cad:$id");
            }
        }

        $result = (new DerivedQuantityFactory)->derive($this->snapshot($entities, $facts, $evidence), [], $this->request(
            'sloped_roof_area',
            'roof:1',
            [
                'plan_areas' => ['facet-1-plan-area', 'facet-2-plan-area'],
                'slope_rises' => ['facet-1-rise', 'facet-2-rise'],
                'slope_runs' => ['facet-1-run', 'facet-2-run'],
            ],
        ));

        self::assertTrue($result->isReady());
        self::assertSame('30', $result->quantity?->value);

        $foreign = [...$entities, $this->entity('facet:foreign', 'roof_facet', ['roof_id' => 'roof:2'])];
        $foreignFact = $this->fact('foreign-area', 'facet:foreign', 'plan_area', '1', 'm2', ['evidence:foreign']);
        $request = $this->request('sloped_roof_area', 'roof:1', [
            'plan_areas' => ['foreign-area'], 'slope_rises' => ['facet-1-rise'], 'slope_runs' => ['facet-1-run'],
        ]);
        $blocked = (new DerivedQuantityFactory)->derive(
            $this->snapshot($foreign, [...$facts, $foreignFact], [...$evidence, $this->evidence('evidence:foreign', 'cad:foreign')]),
            [],
            $request,
        );
        self::assertSame('entity_scope_mismatch', $blocked->unresolvedInputs[0]['code'] ?? null);
    }

    #[Test]
    public function earthwork_and_technology_package_require_proven_inputs_and_current_decision(): void
    {
        $facts = [
            $this->fact('site-area', 'site:1', 'area', '100.125', 'm2', ['evidence:site-area']),
            $this->fact('depth', 'site:1', 'depth', '0.35', 'm', ['evidence:depth']),
            $this->fact('coefficient', 'package:leveling', 'coefficient', '1.05', 'count', [], 'user_assumption'),
        ];
        $entities = [$this->entity('site:1', 'site'), $this->entity('package:leveling', 'technology_work_package')];
        $evidence = [$this->evidence('evidence:site-area', 'cad:site-area'), $this->evidence('evidence:depth', 'section:depth')];
        $decision = $this->decision('decision:leveling', 'coefficient');
        $snapshot = $this->snapshot($entities, $facts, $evidence);

        $earthwork = (new DerivedQuantityFactory)->derive($snapshot, [], $this->request(
            'earthwork_volume', 'site:1', ['area' => 'site-area', 'depth' => 'depth'], 'm3', 3,
        ));
        self::assertSame('35.044', $earthwork->quantity?->value);

        $request = $this->request(
            'technology_work_package',
            'package:leveling',
            ['base_quantity' => 'site-area', 'coefficient' => 'coefficient'],
        );
        $request['technology_decision_id'] = $decision->id;
        $request['applicable_system_id'] = 'site-leveling:v1';
        $request['technology_status'] = 'current';
        $request['technology_applicable'] = true;
        $request['technology_availability'] = 'available';
        $package = (new DerivedQuantityFactory)->derive($snapshot, [$decision], $request);
        self::assertSame('105.13', $package->quantity?->value);
        self::assertSame($decision->id, $package->quantity?->technologyDecisionId);

        $blocked = (new DerivedQuantityFactory)->derive($snapshot, [], $request);
        self::assertSame('decision_missing', $blocked->unresolvedInputs[0]['code'] ?? null);
    }

    #[Test]
    public function non_current_wrong_unit_missing_evidence_and_cross_source_operands_are_unresolved(): void
    {
        foreach ([
            $this->fact('candidate', 'room:1', 'length', '2', 'm', ['evidence:one'], 'document', 'candidate'),
            $this->fact('wrong-unit', 'room:1', 'length', '2', 'kg', ['evidence:one']),
            $this->fact('missing-evidence', 'room:1', 'length', '2', 'm', [], 'user_assumption'),
            $this->fact('cross-source', 'room:1', 'length', '2', 'm', ['evidence:one'], sourceVersion: 'sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'),
        ] as $invalid) {
            $valid = $this->fact('width', 'room:1', 'width', '3', 'm', ['evidence:width']);
            $result = (new DerivedQuantityFactory)->derive(
                $this->snapshot(
                    [$this->entity('room:1', 'room')],
                    [$invalid, $valid],
                    [$this->evidence('evidence:one', 'native:one'), $this->evidence('evidence:width', 'native:width')],
                ),
                [],
                $this->request('floor_area', 'room:1', ['length' => $invalid->id, 'width' => 'width']),
            );
            self::assertFalse($result->isReady(), $invalid->id);
            self::assertNotSame([], $result->unresolvedInputs, $invalid->id);
            self::assertNull($result->quantity, $invalid->id);
        }
    }

    #[Test]
    public function input_order_is_stable_and_rounding_occurs_only_at_formula_boundary(): void
    {
        $facts = [
            $this->fact('length', 'room:1', 'length', '1.005', 'm', ['evidence:length']),
            $this->fact('width', 'room:1', 'width', '1', 'm', ['evidence:width']),
        ];
        $snapshot = $this->snapshot(
            [$this->entity('room:1', 'room')],
            $facts,
            [$this->evidence('evidence:length', 'native:length'), $this->evidence('evidence:width', 'native:width')],
        );
        $first = (new DerivedQuantityFactory)->derive($snapshot, [], $this->request(
            'floor_area', 'room:1', ['length' => 'length', 'width' => 'width'], 'm2', 2,
        ));
        $second = (new DerivedQuantityFactory)->derive(
            $this->snapshot([$this->entity('room:1', 'room')], array_reverse($facts), array_reverse($snapshot->evidence)),
            [],
            $this->request('floor_area', 'room:1', ['width' => 'width', 'length' => 'length'], 'm2', 2),
        );

        self::assertSame('1.01', $first->quantity?->value);
        self::assertSame($first->quantity?->id, $second->quantity?->id);
        self::assertSame($first->quantity?->operands, $second->quantity?->operands);
    }

    #[Test]
    public function bounded_processing_returns_typed_budget_failure(): void
    {
        $facts = [];
        $evidence = [];
        for ($index = 1; $index <= 33; $index++) {
            $facts[] = $this->fact("length:$index", 'room:1', 'length', '1', 'm', ["evidence:$index"]);
            $evidence[] = $this->evidence("evidence:$index", "native:$index");
        }
        $request = $this->request('floor_area', 'room:1', [
            'length' => array_map(static fn (int $index): string => "length:$index", range(1, 33)),
            'width' => 'length:1',
        ]);
        $request['limits'] = ['max_operands' => 32, 'max_evidence' => 64, 'max_metadata_bytes' => 32768];

        $result = (new DerivedQuantityFactory)->derive(
            $this->snapshot([$this->entity('room:1', 'room')], $facts, $evidence),
            [],
            $request,
        );

        self::assertSame('operand_budget_exceeded', $result->unresolvedInputs[0]['code'] ?? null);
    }

    #[Test]
    public function roof_openings_are_scoped_deduplicated_and_subtracted(): void
    {
        $entities = [
            $this->entity('roof:1', 'roof'),
            $this->entity('facet:1', 'roof_facet', ['roof_id' => 'roof:1']),
            $this->entity('roof-opening:1', 'roof_opening', ['roof_id' => 'roof:1', 'geometry_identity' => 'opening:g1']),
        ];
        $facts = [
            $this->fact('area', 'facet:1', 'plan_area', '12', 'm2', ['evidence:area']),
            $this->fact('rise', 'facet:1', 'slope_rise', '3', 'm', ['evidence:rise']),
            $this->fact('run', 'facet:1', 'slope_run', '4', 'm', ['evidence:run']),
            $this->fact('opening-area', 'roof-opening:1', 'area', '1.25', 'm2', ['evidence:opening']),
        ];
        $evidence = array_map(fn (Fact $fact): Evidence => $this->evidence($fact->evidenceIds[0], 'native:'.$fact->id), $facts);
        $request = $this->request('sloped_roof_area', 'roof:1', [
            'plan_areas' => ['area'], 'slope_rises' => ['rise'], 'slope_runs' => ['run'],
            'roof_opening_areas' => ['opening-area'],
        ]);

        $result = (new DerivedQuantityFactory)->derive($this->snapshot($entities, $facts, $evidence), [], $request);

        self::assertSame('13.75', $result->quantity?->value);
    }

    #[Test]
    public function invalidated_conflicted_null_unit_float_and_lost_evidence_never_produce_quantity(): void
    {
        $cases = [
            $this->fact('invalidated', 'room:1', 'length', '2', 'm', ['evidence:bad'], status: 'invalidated'),
            $this->fact('conflicted', 'room:1', 'length', '2', 'm', ['evidence:bad'], status: 'conflicted'),
            $this->fact('null-unit', 'room:1', 'length', '2', null, ['evidence:bad']),
            new Fact('float', 1, 2, 3, self::SOURCE_VERSION, 'room:1', 'length', 2.5, 'm', 1.0, 'document', 'confirmed', ['evidence:bad']),
            $this->fact('lost-evidence', 'room:1', 'length', '2', 'm', ['evidence:lost']),
            $this->fact('zero', 'room:1', 'length', '0', 'm', ['evidence:bad']),
            $this->fact('negative', 'room:1', 'length', '-2', 'm', ['evidence:bad']),
        ];
        foreach ($cases as $fact) {
            $result = (new DerivedQuantityFactory)->derive($this->snapshot(
                [$this->entity('room:1', 'room')],
                [$fact, $this->fact('width', 'room:1', 'width', '3', 'm', ['evidence:width'])],
                [$this->evidence('evidence:bad', 'native:bad'), $this->evidence('evidence:width', 'native:width')],
            ), [], $this->request('floor_area', 'room:1', ['length' => $fact->id, 'width' => 'width']));

            self::assertFalse($result->isReady(), $fact->id);
            self::assertNull($result->quantity, $fact->id);
        }
    }

    #[Test]
    public function stale_conditional_or_unavailable_technology_cannot_become_confirmed_quantity(): void
    {
        $facts = [
            $this->fact('base', 'package:1', 'area', '10', 'm2', ['evidence:base']),
            $this->fact('coefficient', 'package:1', 'coefficient', '1', 'count', [], 'user_assumption'),
        ];
        $snapshot = $this->snapshot(
            [$this->entity('package:1', 'technology_work_package')],
            $facts,
            [$this->evidence('evidence:base', 'native:base')],
        );
        $decision = $this->decision('decision:package', 'coefficient');
        $request = $this->request('technology_work_package', 'package:1', [
            'base_quantity' => 'base', 'coefficient' => 'coefficient',
        ]);
        $request += [
            'technology_decision_id' => $decision->id,
            'applicable_system_id' => 'system:v1',
            'technology_status' => 'current',
            'technology_applicable' => true,
            'technology_availability' => 'available',
        ];

        foreach ([
            ['technology_status', 'stale'],
            ['technology_applicable', false],
            ['technology_availability', 'unavailable'],
        ] as [$key, $value]) {
            $blockedRequest = [...$request, $key => $value];
            $result = (new DerivedQuantityFactory)->derive($snapshot, [$decision], $blockedRequest);
            self::assertSame('decision_missing', $result->unresolvedInputs[0]['code'] ?? null);
        }
    }

    private function request(
        string $formula,
        string $entityId,
        array $operands,
        string $unit = 'm2',
        int $scale = 2,
    ): array {
        return [
            'quantity_id' => 'quantity:'.$formula.':'.$entityId,
            'formula_identity' => $formula,
            'formula_version' => '1',
            'entity_id' => $entityId,
            'operands' => $operands,
            'output_unit' => $unit,
            'rounding_mode' => 'half_up',
            'rounding_scale' => $scale,
            'snapshot' => [
                'input_fingerprint' => str_repeat('b', 64),
                'artifact_hash' => str_repeat('c', 64),
                'catalog_version' => 'technology-catalog:v1',
                'catalog_hash' => str_repeat('d', 64),
                'rule_version' => 'completeness-rules:v1',
                'rule_hash' => str_repeat('e', 64),
            ],
            'limits' => ['max_operands' => 128, 'max_evidence' => 256, 'max_metadata_bytes' => 65536],
        ];
    }

    private function entity(string $id, string $type, array $attributes = []): Entity
    {
        if (! in_array($type, Entity::TYPES, true)) {
            $attributes = ['semantic_type' => $type, ...$attributes];
            $type = 'quantity';
        }

        return new Entity($id, 1, 2, 3, self::SOURCE_VERSION, $type, $id, $attributes);
    }

    private function fact(
        string $id,
        string $entityId,
        string $type,
        string $value,
        ?string $unit,
        array $evidenceIds,
        string $origin = 'document',
        string $status = 'confirmed',
        int $version = 1,
        ?string $sourceVersion = null,
    ): Fact {
        return new Fact(
            $id, 1, 2, 3, $sourceVersion ?? self::SOURCE_VERSION, $entityId, $type, $value, $unit,
            1.0, $origin, $status, $evidenceIds, $version,
        );
    }

    private function evidence(string $id, string $nativeReference): Evidence
    {
        return new Evidence($id, 1, 2, 3, self::SOURCE_VERSION, 'artifact:1', 'cad', nativeReference: $nativeReference);
    }

    private function decision(string $id, string $selectedFactId): Decision
    {
        return new Decision(
            $id, 1, 2, 3, self::SOURCE_VERSION, 'fact', $selectedFactId, $selectedFactId,
            'user', 'actor:42', 'Подтверждено пользователем', 1,
        );
    }

    private function snapshot(array $entities, array $facts, array $evidence): ProjectModelSnapshot
    {
        return new ProjectModelSnapshot($entities, $facts, $evidence, []);
    }
}
