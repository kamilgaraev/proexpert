<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\RoofSystemSpecification;
use App\BusinessModules\Addons\EstimateGeneration\Quantities\RoofSystemScope;
use PHPUnit\Framework\TestCase;

final class RoofSystemSpecificationTest extends TestCase
{
    public function test_material_choices_require_distinct_coverings_and_do_not_treat_bitumen_on_a_cement_base_as_concrete_tiles(): void
    {
        foreach (RoofSystemScope::SYSTEMS as $system) {
            self::assertTrue(RoofSystemSpecification::compatible($system, RoofSystemSpecification::searchText($system)));
        }
        foreach (['Устройство кровли из металлочерепицы', 'Устройство кровель из черепицы битумной по цементно-песчаной стяжке',
            'Устройство кровли из керамической черепицы', 'Устройство цементно-песчаной стяжки кровли', 'Монтаж бетонной черепицы на фасаде'] as $wrong) {
            self::assertFalse(RoofSystemSpecification::compatible('concrete_tile', $wrong), $wrong);
        }
        self::assertTrue(RoofSystemSpecification::compatible('concrete_tile', 'Устройство кровель из черепицы: керамической и цементно-песчаной (бетонной)'));
    }

    public function test_current_material_replaces_only_the_covering_default_and_keeps_its_source_binding_and_search_suffix(): void
    {
        $item = ['key' => 'independent-covering', 'name' => 'Покрытие кровли', 'quantity' => '87', 'unit' => 'm2',
            'normative_rate_code' => '12-01-023-01', 'work_intent' => ['material' => 'металлочерепица'],
            'specialization_scenario' => ['old' => true], 'specialization_evidence' => ['old' => true],
            'metadata' => ['composition_work_key' => 'roof.covering', 'scope_entity_id' => 'roof:independent',
                'scope_entity_binding' => ['fact_id' => 'fact:binding'], 'roof_system' => ['system' => 'concrete_tile', 'fact_id' => 'fact:current-material'],
                'specialization_scenario' => ['old' => true], 'material_assumption' => ['old' => true], 'material_scenario_work_key' => 'roof.covering']];
        $result = RoofSystemSpecification::specialize($item);
        self::assertSame('87', $result['quantity']);
        self::assertSame($item['metadata']['scope_entity_binding'], $result['metadata']['scope_entity_binding']);
        self::assertSame($item['metadata']['roof_system'], $result['metadata']['roof_system']);
        foreach (['normative_rate_code', 'work_intent', 'specialization_scenario', 'specialization_evidence'] as $field) {
            self::assertArrayNotHasKey($field, $result);
        }
        foreach (['specialization_scenario', 'material_assumption', 'material_scenario_work_key'] as $field) {
            self::assertArrayNotHasKey($field, $result['metadata']);
        }
        $result['normative_search_text'] .= ' 12-01-007';
        self::assertStringEndsWith(' 12-01-007', RoofSystemSpecification::specialize($result)['normative_search_text']);
        $unrelated = [...$item, 'metadata' => [...$item['metadata'], 'composition_work_key' => 'roof.membrane']];
        self::assertSame($unrelated, RoofSystemSpecification::specialize($unrelated));
        unset($item['metadata']['roof_system']);
        self::assertSame($item, RoofSystemSpecification::specialize($item));
    }
}
