<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Planning;

use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Entity;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\Fact;
use App\BusinessModules\Addons\EstimateGeneration\Domain\ProjectModel\ProjectModelSnapshot;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessFinding;
use App\BusinessModules\Addons\EstimateGeneration\Planning\CompletenessRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Planning\ProjectCompletenessAnalyzer;
use App\BusinessModules\Addons\EstimateGeneration\Planning\TechnologyWorkPackageBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompletenessScopedPrerequisitesTest extends TestCase
{
    public function test_only_the_applicable_entity_supplies_a_quantity_regardless_of_global_fact_order(): void
    {
        $facts = [$this->fact('type', 'a', 'foundation_type', 'slab'),
            $this->fact('foreign-area', 'b', 'foundation_area', '900', 'm2'),
            $this->fact('area', 'a', 'foundation_area', '40', 'm2')];

        foreach ([$facts, array_reverse($facts)] as $ordered) {
            $formula = $this->finding($ordered)->workPackage->quantityFormulas[0];
            self::assertSame('40', $formula['resolved_value']);
            self::assertSame('fact:area', $formula['operands'][0]['fact_id']);
        }
    }

    #[DataProvider('invalidOperands')]
    public function test_wrong_dimension_source_status_or_numeric_kind_remains_unresolved(mixed $value, ?string $unit, string $source, string $status): void
    {
        $finding = $this->finding([$this->fact('type', 'a', 'foundation_type', 'slab'),
            $this->fact('area', 'a', 'foundation_area', $value, $unit, $source, $status)]);
        $formula = $finding->workPackage->quantityFormulas[0];

        self::assertSame(['foundation_area'], $formula['unresolved_inputs']);
        self::assertSame([], $formula['operands']);
        self::assertArrayNotHasKey('resolved_value', $formula);
    }

    public static function invalidOperands(): array
    {
        return [
            'volume is not area' => ['40', 'm3', 'a', 'confirmed'],
            'missing unit' => ['40', null, 'a', 'confirmed'],
            'another source version' => ['40', 'm2', 'b', 'confirmed'],
            'unconfirmed' => ['40', 'm2', 'a', 'candidate'],
            'boolean' => [true, 'm2', 'a', 'confirmed'],
            'negative' => ['-40', 'm2', 'a', 'confirmed'],
            'zero' => ['0', 'm2', 'a', 'confirmed'],
            'scientific notation' => ['4e1', 'm2', 'a', 'confirmed'],
        ];
    }

    public function test_multiple_applicable_entities_and_conflicting_facts_do_not_choose_a_first_value(): void
    {
        foreach ([
            [$this->fact('type', 'a', 'foundation_type', 'slab'), $this->fact('area', 'a', 'foundation_area', '40', 'm2'),
                $this->fact('area-2', 'a', 'foundation_area', '50', 'm2')],
            [$this->fact('type', 'a', 'foundation_type', 'slab'), $this->fact('type-2', 'b', 'foundation_type', 'slab'),
                $this->fact('area', 'a', 'foundation_area', '40', 'm2'), $this->fact('area-2', 'b', 'foundation_area', '50', 'm2')],
        ] as $facts) {
            foreach ([$facts, array_reverse($facts)] as $ordered) {
                $formula = $this->finding($ordered)->workPackage->quantityFormulas[0];
                self::assertSame(['foundation_area'], $formula['unresolved_inputs']);
                self::assertSame([], $formula['operands']);
            }
        }
    }

    public function test_the_foundation_variant_comes_from_the_applicable_entity(): void
    {
        $finding = $this->finding([$this->fact('unrelated-type', 'b', 'foundation_type', 'unsupported'),
            $this->fact('type', 'a', 'foundation_type', 'pile'), $this->fact('piles', 'a', 'pile_count', '12', 'item')]);

        self::assertSame('package:base_preparation_pile', $finding->workPackage->id);
        self::assertSame('12', $finding->workPackage->quantityFormulas[0]['resolved_value']);
    }

    public function test_conflicting_variants_are_reported_without_crashing_or_guessing_a_package(): void
    {
        $finding = $this->finding([$this->fact('type', 'a', 'foundation_type', 'slab'),
            $this->fact('type-2', 'b', 'foundation_type', 'pile')]);

        self::assertNull($finding->workPackage);
        self::assertSame('applicable', $finding->applicability['status']);
    }

    public function test_counts_require_integral_values(): void
    {
        $finding = $this->finding([$this->fact('type', 'a', 'foundation_type', 'pile'),
            $this->fact('piles', 'a', 'pile_count', '12.5', 'item')]);
        self::assertSame(['pile_count'], $finding->workPackage->quantityFormulas[0]['unresolved_inputs']);
    }

    public function test_an_obsolete_applicability_source_or_missing_entity_cannot_supply_an_answer_target(): void
    {
        foreach ([
            [$this->fact('type', 'a', 'foundation_type', 'slab', source: 'b'), $this->fact('area', 'a', 'foundation_area', '40', 'm2')],
            [$this->fact('type', 'missing', 'foundation_type', 'slab'), $this->fact('area', 'missing', 'foundation_area', '40', 'm2')],
        ] as $facts) {
            self::assertNull($this->finding($facts)->workPackage);
        }
    }

    public function test_conditions_on_different_entities_do_not_establish_a_shared_scope(): void
    {
        $finding = $this->finding([$this->fact('type', 'a', 'foundation_type', 'slab'),
            $this->fact('below-grade', 'b', 'below_grade_structure', true)], [
                ['fact_type' => 'foundation_type', 'operator' => 'in', 'values' => ['slab']],
                ['fact_type' => 'below_grade_structure', 'operator' => '=', 'value' => true],
            ]);

        self::assertSame('unknown', $finding->applicability['status']);
        self::assertNull($finding->workPackage);
    }

    public function test_roof_plan_area_is_not_an_implicit_substitute_for_a_roof_surface(): void
    {
        self::assertFalse(\App\BusinessModules\Addons\EstimateGeneration\Planning\PrerequisiteInputDefinition::accepts('roof_plan_area', '40', 'm2', 'm2'));
    }

    public function test_large_ambiguous_scope_keeps_provenance_bounded_and_never_invents_an_aggregate(): void
    {
        $entities = $types = $areas = [];
        foreach (range(1, 5000) as $index) {
            $entities[] = new Entity('entity:foundation-'.$index, 38, 104, 85, 'sha256:'.str_repeat('a', 64), 'quantity', 'entity:foundation-'.$index);
            $types[] = $this->fact('type-'.$index, 'foundation-'.$index, 'foundation_type', 'slab');
            $areas[] = $this->fact('area-'.$index, 'foundation-'.$index, 'foundation_area', '40', 'm2');
        }
        $catalog = CompletenessRuleCatalog::fromArray(require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php');
        $rule = array_values(array_filter($catalog->rules(), static fn ($rule): bool => $rule->id === 'base_preparation'))[0];
        $package = (new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Название'))
            ->build($rule, ['foundation_type' => $types, 'foundation_area' => $areas], $entities);

        self::assertSame(['foundation_area'], $package->quantityFormulas[0]['unresolved_inputs']);
        self::assertSame(['status' => 'ambiguous', 'entity_count' => 5000], $package->provenance['input_scope']);
        self::assertLessThan(512, strlen(json_encode($package->provenance, JSON_THROW_ON_ERROR)));
    }

    private function finding(array $facts, ?array $conditions = null): CompletenessFinding
    {
        $data = require dirname(__DIR__, 4).'/config/estimate-generation-completeness-rules.php';
        if ($conditions !== null) {
            foreach ($data['rules'] as &$rule) {
                if ($rule['id'] === 'base_preparation') {
                    $rule['conditions'] = $conditions;
                    $rule['applicability_fact_types'] = array_column($conditions, 'fact_type');
                }
            }
            unset($rule);
        }
        $catalog = CompletenessRuleCatalog::fromArray($data);
        $analyzer = new ProjectCompletenessAnalyzer($catalog, new TechnologyWorkPackageBuilder(static fn (string $key): string => 'Название'),
            100, 100, 1000, translate: static fn (string $key): string => 'Проверить');
        $snapshot = new ProjectModelSnapshot([
            new Entity('entity:a', 38, 104, 85, 'sha256:'.str_repeat('a', 64), 'quantity', 'entity:a'),
            new Entity('entity:b', 38, 104, 85, 'sha256:'.str_repeat('a', 64), 'quantity', 'entity:b'),
        ], $facts, [], []);
        $result = $analyzer->analyze($snapshot, []);

        return array_values(array_filter($result->findings, static fn (CompletenessFinding $finding): bool => $finding->ruleId === 'base_preparation'))[0];
    }

    private function fact(string $id, string $entity, string $type, mixed $value, ?string $unit = null, string $source = 'a', string $status = 'confirmed'): Fact
    {
        return new Fact('fact:'.$id, 38, 104, 85, 'sha256:'.str_repeat($source, 64), 'entity:'.$entity, $type, $value, $unit, 0.0, 'user_assumption', $status, []);
    }
}
