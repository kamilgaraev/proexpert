<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeScopeRuleCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\WorkIntentClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorkIntentClassifierTest extends TestCase
{
    #[DataProvider('workIntentProvider')]
    public function test_classifies_private_house_work_intents(
        string $name,
        string $unit,
        string $expectedScope,
        string $expectedAction,
        string $expectedDimension,
        array $forbiddenCollections
    ): void {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => $name,
            'unit' => $unit,
        ], [
            'scope_type' => $expectedScope,
        ]);

        $this->assertSame($expectedScope, $intent->scope);
        $this->assertSame($expectedAction, $intent->action);
        $this->assertContains($expectedDimension, $intent->expectedDimensions);

        foreach ($forbiddenCollections as $collection) {
            $this->assertContains($collection, $intent->forbiddenNormTypes);
        }
    }

    public function test_returns_non_empty_low_confidence_intent_for_unknown_work(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Нестандартная работа по объекту',
            'unit' => 'компл',
        ], []);

        $this->assertNotSame('', $intent->scope);
        $this->assertNotSame('', $intent->action);
        $this->assertContains('piece', $intent->expectedDimensions);
        $this->assertLessThan(0.6, $intent->confidence);
    }

    public function test_classifies_heating_unit_as_equipment_not_pipe_layout(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Тепловой узел',
            'unit' => 'компл',
        ], [
            'scope_type' => 'engineering',
            'section_title' => 'Отопление',
        ]);

        $this->assertSame('engineering', $intent->scope);
        $this->assertSame('heating', $intent->system);
        $this->assertSame('heating_equipment', $intent->action);
        $this->assertContains('piece', $intent->expectedDimensions);
        $this->assertContains('18', $intent->preferredSectionPrefixes);
        $this->assertContains('20', $intent->preferredSectionPrefixes);
        $this->assertNotContains('16', $intent->preferredSectionPrefixes);
    }

    public function test_finishing_work_keeps_finishing_scope_inside_plumbing_package(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Отделка мокрых зон плиткой',
            'unit' => 'м2',
        ], [
            'scope_type' => 'plumbing',
            'section_title' => 'Водоснабжение и канализация',
        ]);

        $this->assertSame('finishing', $intent->scope);
        $this->assertSame('tiling', $intent->action);
        $this->assertContains('15', $intent->preferredSectionPrefixes);
        $this->assertNotContains('16', $intent->preferredSectionPrefixes);
    }

    public function test_pipe_layout_is_not_classified_as_masonry_because_of_prokladka(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Прокладка труб отопления',
            'unit' => 'м',
        ], [
            'scope_type' => 'heating',
            'section_title' => 'Отопление',
        ]);

        $this->assertSame('engineering', $intent->scope);
        $this->assertSame('heating', $intent->system);
        $this->assertSame('pipe_layout', $intent->action);
        $this->assertContains('16', $intent->preferredSectionPrefixes);
        $this->assertNotContains('08', $intent->preferredSectionPrefixes);
    }

    public function test_classifies_soil_transport_separately_from_excavation_and_loading(): void
    {
        $classifier = new WorkIntentClassifier(new NormativeScopeRuleCatalog);

        foreach (['Вывоз излишнего грунта', 'Погрузка и перевозка излишнего грунта'] as $name) {
            $intent = $classifier->classify(['name' => $name, 'unit' => 'м3'], ['scope_type' => 'foundation']);

            self::assertSame('soil_haulage', $intent->action);
            self::assertContains('volume', $intent->expectedDimensions);
            self::assertSame(['01'], $intent->preferredSectionPrefixes);
        }
    }

    public function test_classifies_inflected_reverse_backfill_title(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Обратная засыпка пазух грунтом',
            'unit' => 'м3',
        ], ['scope_type' => 'foundation']);

        self::assertSame('backfill', $intent->action);
        self::assertContains('volume', $intent->expectedDimensions);
        self::assertSame(['01'], $intent->preferredSectionPrefixes);
    }

    public function test_classifies_grounding_as_electrical_installation(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Устройство контура заземления',
            'unit' => 'м',
        ], ['scope_type' => 'engineering']);

        self::assertSame('electrical', $intent->system);
        self::assertSame('grounding_installation', $intent->action);
        self::assertSame(['08'], $intent->preferredSectionPrefixes);
    }

    public function test_classifies_rough_floor_preparation_separately_from_finish_covering(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Устройство черновой подготовки пола',
            'unit' => 'м2',
        ], ['scope_type' => 'finishing']);

        self::assertSame('floor_preparation', $intent->action);
        self::assertSame(['11'], $intent->preferredSectionPrefixes);
    }

    #[DataProvider('distinctInstallationIntentProvider')]
    public function test_classifies_distinct_installation_targets(string $name, string $section, string $expectedAction): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => $name,
            'unit' => 'шт',
        ], [
            'scope_type' => 'engineering',
            'section_title' => $section,
        ]);

        self::assertSame($expectedAction, $intent->action);
    }

    public static function distinctInstallationIntentProvider(): array
    {
        return [
            ['Монтаж кабельных лотков', 'Электроснабжение', 'cable_tray_installation'],
            ['Монтаж сантехнических точек', 'Водоснабжение', 'sanitary_fixture_installation'],
            ['Монтаж дверных блоков', 'Окна и двери', 'door_installation'],
            ['Монтаж канализационных ревизий', 'Канализация', 'sewer_revision_installation'],
            ['Монтаж канализационных стояков', 'Канализация', 'sewer_riser_installation'],
            ['Устройство выпусков канализации', 'Канализация', 'sewer_outlet_installation'],
        ];
    }

    public function test_cable_laying_on_trays_remains_cable_installation(): void
    {
        $intent = (new WorkIntentClassifier(new NormativeScopeRuleCatalog))->classify([
            'name' => 'Прокладка кабеля по лоткам',
            'unit' => 'м',
        ], [
            'scope_type' => 'engineering',
            'section_title' => 'Электроснабжение',
        ]);

        self::assertSame('cable_installation', $intent->action);
    }

    public static function workIntentProvider(): array
    {
        return [
            ['Утепление кровли 200 мм', 'м2', 'roof', 'insulation', 'area', ['gesn_earthwork']],
            ['Разводка труб отопления', 'м', 'engineering', 'pipe_layout', 'length', ['gesn_earthwork']],
            ['Прокладка кабельных линий', 'м', 'engineering', 'cable_installation', 'length', ['gesn_earthwork']],
            ['Кладка наружных стен из газобетона D500 400 мм', 'м3', 'walls', 'masonry', 'volume', ['gesn_earthwork']],
            ['Фасадная штукатурка по газобетону', 'м2', 'facade', 'plastering', 'area', ['gesn_earthwork']],
            ['Воздушно-тепловые завесы ворот', 'шт', 'engineering', 'heating_equipment', 'piece', ['gesn_earthwork']],
            ['Монтаж вентиляции', 'м2', 'engineering', 'ventilation_installation', 'area', ['gesn_earthwork']],
            ['Установка окон и дверей', 'шт', 'openings', 'window_installation', 'piece', ['gesn_earthwork']],
            ['Монтаж плинтусов', 'м', 'finishing', 'baseboard_installation', 'length', ['gesn_earthwork']],
            ['Окраска стен', 'м2', 'finishing', 'painting', 'area', ['gesn_earthwork']],
            ['Облицовка мокрых зон плиткой', 'м2', 'finishing', 'tiling', 'area', ['gesn_earthwork']],
            ['Устройство чистового покрытия пола', 'м2', 'finishing', 'floor_covering', 'area', ['gesn_earthwork']],
            ['Покрытие пола линолеумом', 'м2', 'finishing', 'floor_covering', 'area', ['gesn_earthwork']],
            ['Монтаж подвесного потолка', 'м2', 'finishing', 'ceiling_finishing', 'area', ['gesn_earthwork']],
            ['Временное ограждение площадки', 'м', 'temporary', 'fence_installation', 'length', ['gesn_earthwork']],
            ['Устройство лестничных маршей', 'м2', 'stairs', 'general_work', 'area', ['gesn_earthwork']],
            ['Опалубка ленточного фундамента', 'м2', 'foundation', 'formwork', 'area', []],
            ['Армирование фундаментной ленты', 'т', 'foundation', 'reinforcement', 'mass', []],
            ['Бетонирование фундаментной ленты B22.5', 'м3', 'foundation', 'concreting', 'volume', []],
        ];
    }
}
