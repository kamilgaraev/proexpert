<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeCandidateSelectionHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSearchProfileCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use PHPUnit\Framework\TestCase;

final class NormativeCandidateSelectionHardGateTest extends TestCase
{
    public function test_rejects_office_ventilation_norm_for_residential_heating_work(): void
    {
        $reasons = $this->gate()->rejectionReasons(
            [
                'name' => 'Установка радиаторов отопления',
                'normative_search_text' => 'Монтаж радиаторов отопления',
                'unit' => 'шт',
            ],
            [
                'scope_type' => 'engineering',
                'section_title' => 'Отопление',
                'object_type' => 'residential',
            ],
            [
                'selected' => [
                    'name' => 'Монтаж воздухораспределителей офиса',
                    'unit' => 'шт',
                    'object_type' => 'office',
                    'section' => ['code' => '20'],
                    'work_composition' => ['Монтаж вентиляционного оборудования офиса'],
                ],
            ],
        );

        self::assertContains('object_type_mismatch', $reasons);
        self::assertContains('semantic_mismatch', $reasons);
    }

    public function test_accepts_residential_radiator_norm_for_heating_work(): void
    {
        $reasons = $this->gate()->rejectionReasons(
            [
                'name' => 'Установка радиаторов отопления',
                'normative_search_text' => 'Монтаж радиаторов отопления',
                'unit' => 'шт',
            ],
            [
                'scope_type' => 'engineering',
                'section_title' => 'Отопление',
                'object_type' => 'residential',
            ],
            [
                'selected' => [
                    'name' => 'Установка радиаторов отопительных',
                    'unit' => 'шт',
                    'object_type' => 'residential',
                    'section' => ['code' => '18'],
                    'work_composition' => ['Установка радиаторов в жилом доме'],
                ],
            ],
        );

        self::assertSame([], $reasons);
    }

    public function test_rejects_incompatible_unit_before_selection(): void
    {
        $reasons = $this->gate()->rejectionReasons(
            ['name' => 'Кладка стен', 'unit' => 'м2'],
            ['scope_type' => 'walls', 'object_type' => 'residential'],
            [
                'selected' => [
                    'name' => 'Кладка стен из блоков',
                    'unit' => 'м3',
                    'section' => ['code' => '08'],
                ],
            ],
        );

        self::assertContains('unit_mismatch', $reasons);
    }

    public function test_signed_residential_scenario_survives_final_selection_gate(): void
    {
        $scenario = (new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog)
            ->issue('sanitary.showers', 'residential');
        self::assertIsArray($scenario);

        $reasons = $this->gate()->rejectionReasons(
            [
                'name' => 'Установка душевых кабин с пластиковым поддоном',
                'normative_search_text' => $scenario['normative_search_text'],
                'normative_rate_code' => $scenario['normative_rate_code'],
                'unit' => 'pcs',
                'specialization_scenario' => $scenario,
                'work_intent' => [
                    'scope' => 'engineering',
                    'action' => 'sanitary_fixture_installation',
                    'preferred_section_prefixes' => ['17'],
                    'specialization_scenario' => $scenario,
                ],
            ],
            ['scope_type' => 'engineering', 'section_title' => 'Водоснабжение', 'object_type' => 'residential'],
            ['selected' => [
                'code' => '17-01-001-21',
                'name' => 'Установка кабин душевых: с пластиковыми поддонами',
                'unit' => '10 компл',
                'section' => ['code' => '17-01'],
                'work_composition' => [],
            ]],
        );

        self::assertSame([], $reasons);
    }

    private function gate(): NormativeCandidateSelectionHardGate
    {
        return new NormativeCandidateSelectionHardGate(
            new WorkIntentClassifier(new NormativeScopeRuleCatalog),
            new NormativeSearchProfileCatalog,
            new NormativeSemanticCompatibilityService,
        );
    }
}
