<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\Normatives\NormativeSemanticCompatibilityService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NormativeSemanticCompatibilityServiceTest extends TestCase
{
    public function test_exposes_the_same_action_vocabulary_for_retrieval_and_validation(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertNotEmpty($service->markersForAction('insulation'));
        self::assertNotEmpty($service->markersForAction('concreting'));
        self::assertNotEmpty($service->markersForAction('fence_installation'));
        self::assertSame([], $service->markersForAction('unknown_action'));
    }

    public function test_reinforced_concrete_context_does_not_turn_formwork_into_concreting(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Монтаж мелкощитовой опалубки монолитных железобетонных конструкций фундаментов',
            'Бетонирование железобетонных фундаментов',
            ['action' => 'concreting'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство монолитных фундаментов. Укладка бетонной смеси в опалубку',
            'Бетонирование железобетонных фундаментов',
            ['action' => 'concreting'],
        ));
    }

    #[DataProvider('incompatibleResidentialNorms')]
    public function test_rejects_norms_that_do_not_match_residential_work_semantics(
        string $workText,
        string $candidateText,
        string $action,
    ): void {
        $service = new NormativeSemanticCompatibilityService;

        $this->assertFalse($service->isCompatible(
            $candidateText,
            $workText,
            ['scope' => 'foundation', 'action' => $action],
        ));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function incompatibleResidentialNorms(): array
    {
        return [
            'temporary fence is not grounding conductor' => [
                'Временное ограждение площадки',
                'Проводник заземляющий открыто по строительным основаниям из полосовой стали',
                'fence_installation',
            ],
            'foundation concrete is not reactor construction' => [
                'Бетонирование фундаментов',
                'Бетонирование конструкций шахты реактора: электропрогрев серпентинитового бетона сухой защиты реактора',
                'concreting',
            ],
            'foundation reinforcement is not reactor construction' => [
                'Армирование фундаментов',
                'Установка арматуры реакторного отделения краном СКР',
                'reinforcement',
            ],
            'backfill is not excavation' => [
                'Обратная засыпка пазух',
                'Разработка грунта в траншеях экскаватором обратная лопата',
                'backfill',
            ],
            'residential excavation is not hydroenergy quarry work' => [
                'Вывоз излишнего грунта',
                'Разработка грунта с погрузкой карьерными экскаваторами в гидроэнергетическом строительстве',
                'excavation',
            ],
            'partitions are not wall drainage' => [
                'Внутренние перегородки',
                'Устройство систем дренажа внутренних поверхностей стен',
                'general_work',
            ],
            'wall masonry is not clay insulation' => [
                'Кладка наружных стен',
                'Боковая изоляция стен фундаментов глиной',
                'masonry',
            ],
        ];
    }

    #[DataProvider('compatibleResidentialNorms')]
    public function test_accepts_norms_with_matching_work_semantics(
        string $workText,
        string $candidateText,
        string $action,
    ): void {
        $service = new NormativeSemanticCompatibilityService;

        $this->assertTrue($service->isCompatible(
            $candidateText,
            $workText,
            ['scope' => 'foundation', 'action' => $action],
        ));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function compatibleResidentialNorms(): array
    {
        return [
            'strip foundation formwork' => [
                'Опалубка фундаментов',
                'Монтаж мелкощитовой опалубки фундаментов ленточных',
                'formwork',
            ],
            'foundation waterproofing' => [
                'Гидроизоляция фундаментов',
                'Гидроизоляция боковая обмазочная битумная стен фундаментов',
                'waterproofing',
            ],
            'manual excavation' => [
                'Разработка грунта под фундаменты',
                'Разработка грунта вручную в котлованах глубиной до 2 м',
                'excavation',
            ],
            'block partitions' => [
                'Внутренние перегородки',
                'Кладка перегородок из газобетонных блоков',
                'general_work',
            ],
        ];
    }

    public function test_known_action_is_checked_even_when_work_title_uses_english_words(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Прокладка заземляющего проводника',
            'Temporary site fence installation',
            ['action' => 'fence_installation'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство временного ограждения строительной площадки',
            'Temporary site fence installation',
            ['action' => 'fence_installation'],
        ));
    }

    public function test_soil_haulage_requires_transport_instead_of_loading_only(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Погрузка грунта экскаваторами в автомобили-самосвалы',
            'Вывоз излишнего грунта',
            ['action' => 'soil_haulage', 'scope' => 'foundation'],
        ));
        self::assertTrue($service->isCompatible(
            'Перевозка грунта автомобилями-самосвалами',
            'Вывоз излишнего грунта',
            ['action' => 'soil_haulage', 'scope' => 'foundation'],
        ));
    }

    public function test_conflicting_earthwork_action_in_title_is_not_rescued_by_work_composition(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Разработка грунта в траншеях. Состав работ: обратная засыпка и уплотнение грунта',
            'Обратная засыпка пазух',
            ['action' => 'backfill', 'scope' => 'foundation'],
        ));
        self::assertTrue($service->isCompatible(
            'Обратная засыпка пазух грунтом. Состав работ: уплотнение грунта',
            'Обратная засыпка пазух',
            ['action' => 'backfill', 'scope' => 'foundation'],
        ));
    }

    public function test_planning_of_foundation_base_does_not_match_slope_planning(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Планировка откосов земляных сооружений механизированным способом',
            'Планировка основания под фундаменты',
            ['action' => 'planning', 'scope' => 'foundation', 'object' => 'foundation'],
        ));
        self::assertTrue($service->isCompatible(
            'Планировка площадей механизированным способом',
            'Планировка основания под фундаменты',
            ['action' => 'planning', 'scope' => 'foundation', 'object' => 'foundation'],
        ));
    }

    public function test_operation_polarity_separates_installation_and_dismantling(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Демонтаж кабельных линий',
            'Монтаж кабельных линий',
            ['action' => 'cable_installation', 'scope' => 'engineering'],
        ));
        self::assertTrue($service->isCompatible(
            'Демонтаж кабельных линий',
            'Демонтаж кабельных линий',
            ['action' => 'cable_installation', 'scope' => 'engineering'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство монолитного фундамента. Состав работ: укладка бетонной смеси, демонтаж опалубки',
            'Устройство монолитного фундамента',
            ['action' => 'concreting', 'scope' => 'foundation'],
        ));
    }

    public function test_internal_finishing_does_not_use_facade_norm(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Облицовка фасадов керамическими плитами',
            'Облицовка мокрых зон керамической плиткой',
            ['action' => 'tiling', 'scope' => 'finishing'],
        ));
        self::assertTrue($service->isCompatible(
            'Облицовка фасадов керамическими плитами',
            'Облицовка фасада керамической плиткой',
            ['action' => 'tiling', 'scope' => 'facade'],
        ));
    }

    public function test_primary_work_does_not_use_additive_or_correction_norm(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        foreach ([
            ['Кладка внутренних перегородок', 'Добавлять к нормам кладки перегородок при изменении толщины'],
            ['Кладка наружных стен', 'Добавлять или исключать материалы при корректировке норм кладки'],
            ['Устройство чистового пола', 'На каждый последующий слой покрытия добавлять к нормам'],
            ['Устройство чистового пола', 'Засыпка пустот между элементами пола'],
        ] as [$work, $candidate]) {
            self::assertFalse($service->isCompatible($candidate, $work, ['action' => 'general_work']));
        }

        self::assertTrue($service->isCompatible(
            'На каждый последующий слой покрытия добавлять к нормам',
            'Добавить последующий слой покрытия',
            ['action' => 'general_work'],
        ));
        self::assertTrue($service->isCompatible(
            'Засыпка пустот между элементами пола',
            'Засыпать пустоты между элементами',
            ['action' => 'general_work'],
        ));
    }

    public function test_residential_reinforcement_does_not_use_power_station_norm(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Стыки блок-ячеек резервной дизельной электростанции краном 50 т',
            'Армирование монолитного перекрытия жилого дома',
            ['action' => 'general_work', 'scope' => 'slabs'],
        ));
    }

    public function test_window_and_door_openings_are_not_interchangeable(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Установка дверных блоков шкафных',
            'Монтаж оконных блоков',
            ['action' => 'window_installation', 'scope' => 'openings'],
        ));
        self::assertTrue($service->isCompatible(
            'Установка оконных блоков',
            'Монтаж оконных блоков',
            ['action' => 'window_installation', 'scope' => 'openings'],
        ));
    }

    public function test_work_specific_concept_must_be_present_in_normative_title(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Штукатурка стен. Состав работ: устройство чернового пола',
            'Устройство чернового пола',
            ['action' => 'general_work', 'candidate_title' => 'Штукатурка стен'],
        ));
        self::assertFalse($service->isCompatible(
            'Устройство снегозадержателей. Состав работ: монтаж водосточных труб',
            'Монтаж водосточной системы',
            ['action' => 'general_work', 'candidate_title' => 'Устройство снегозадержателей'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство стропильной системы крыши. Состав работ: монтаж обрешетки',
            'Монтаж стропильной системы',
            ['action' => 'general_work', 'candidate_title' => 'Устройство стропильной системы крыши'],
        ));
    }

    public function test_strong_action_must_be_confirmed_by_normative_title(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Трубопровод стальной 219 мм. Состав работ: прокладка кабеля',
            'Прокладка кабельных линий',
            ['action' => 'cable_installation', 'candidate_title' => 'Трубопровод стальной 219 мм'],
        ));
        self::assertTrue($service->isCompatible(
            'Прокладка кабеля в трубе. Состав работ: прокладка трубы',
            'Прокладка кабельных линий',
            ['action' => 'cable_installation', 'candidate_title' => 'Прокладка кабеля в трубе'],
        ));
    }

    public function test_specialized_radioactive_storage_norm_is_not_used_for_residential_slab(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Армирование перекрытий хранилищ радиоактивных отходов',
            'Армирование монолитного перекрытия жилого дома',
            ['action' => 'reinforcement', 'scope' => 'slabs'],
        ));
        self::assertTrue($service->isCompatible(
            'Армирование монолитных перекрытий жилых зданий',
            'Армирование монолитного перекрытия жилого дома',
            ['action' => 'reinforcement', 'scope' => 'slabs'],
        ));
    }

    public function test_tray_installation_is_not_cable_laying_on_trays(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Прокладка кабеля по установленным лоткам',
            'Монтаж кабельных лотков',
            ['action' => 'cable_tray_installation', 'scope' => 'engineering'],
        ));
        self::assertTrue($service->isCompatible(
            'Монтаж металлических кабельных лотков',
            'Монтаж кабельных лотков',
            ['action' => 'cable_tray_installation', 'scope' => 'engineering'],
        ));
    }

    public function test_plumbing_points_are_not_pressure_gauges(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Установка манометров с трехходовым краном',
            'Монтаж сантехнических точек',
            ['action' => 'sanitary_fixture_installation', 'scope' => 'engineering'],
        ));
        self::assertTrue($service->isCompatible(
            'Установка санитарно-технических приборов: умывальников',
            'Монтаж сантехнических точек',
            ['action' => 'sanitary_fixture_installation', 'scope' => 'engineering'],
        ));
    }

    public function test_building_doors_are_not_cabinet_doors(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Установка дверных блоков шкафных',
            'Монтаж дверных блоков дома',
            ['action' => 'door_installation', 'scope' => 'openings'],
        ));
        self::assertTrue($service->isCompatible(
            'Установка дверных блоков в проемы стен',
            'Монтаж дверных блоков дома',
            ['action' => 'door_installation', 'scope' => 'openings'],
        ));
    }

    public function test_generic_partitions_do_not_assume_glass_blocks(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Кладка перегородок из стеклянных блоков',
            'Устройство внутренних перегородок',
            ['action' => 'masonry', 'scope' => 'walls'],
        ));
        self::assertTrue($service->isCompatible(
            'Кладка перегородок из стеклянных блоков',
            'Устройство внутренних перегородок из стеклоблоков',
            ['action' => 'masonry', 'scope' => 'walls'],
        ));
    }

    public function test_generic_facade_finishing_does_not_use_steel_trim_painting(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Окраска стальных обделок фасада и водосточных труб суриком',
            'Наружная отделка фасада',
            ['action' => 'general_work', 'scope' => 'facade'],
        ));
        self::assertTrue($service->isCompatible(
            'Окраска стальных обделок фасада и водосточных труб суриком',
            'Окраска стальных обделок фасада и водосточных труб',
            ['action' => 'painting', 'scope' => 'facade'],
        ));
    }

    public function test_stair_platform_does_not_use_march_only_norm(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Устройство опалубки лестничных маршей',
            'Устройство лестничных площадок',
            ['action' => 'general_work', 'scope' => 'stairs'],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство лестничных площадок',
            'Устройство лестничных площадок',
            ['action' => 'general_work', 'scope' => 'stairs'],
        ));
    }

    public function test_separate_roof_work_rejects_bundle_with_another_planned_operation(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Устройство кровельного покрытия. Состав работ: установка стропил и укладка покрытия',
            'Устройство кровельного покрытия',
            [
                'action' => 'general_work',
                'scope' => 'roof',
                'candidate_title' => 'Устройство кровельного покрытия',
            ],
        ));
        self::assertTrue($service->isCompatible(
            'Устройство кровельного покрытия. Состав работ: укладка покрытия и устройство примыканий',
            'Устройство кровельного покрытия',
            [
                'action' => 'general_work',
                'scope' => 'roof',
                'candidate_title' => 'Устройство кровельного покрытия',
            ],
        ));
    }

    #[DataProvider('sewerComponentCompatibilityProvider')]
    public function test_sewer_components_do_not_use_network_tie_in_norm(
        string $action,
        string $work,
        string $relevantCandidate,
    ): void {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Врезка в действующую сеть канализации диаметром 50 мм',
            $work,
            ['action' => $action, 'scope' => 'engineering', 'system' => 'sewerage'],
        ));
        self::assertTrue($service->isCompatible(
            $relevantCandidate,
            $work,
            ['action' => $action, 'scope' => 'engineering', 'system' => 'sewerage'],
        ));
    }

    public static function sewerComponentCompatibilityProvider(): array
    {
        return [
            ['sewer_revision_installation', 'Монтаж канализационных ревизий', 'Установка ревизий на внутренних канализационных трубопроводах'],
            ['sewer_riser_installation', 'Монтаж канализационных стояков', 'Прокладка стояков внутренней канализации'],
            ['sewer_outlet_installation', 'Устройство выпусков канализации', 'Устройство выпусков внутренней канализации'],
        ];
    }

    public function test_internal_sewer_pipe_does_not_use_trench_norm(): void
    {
        $service = new NormativeSemanticCompatibilityService;

        self::assertFalse($service->isCompatible(
            'Прокладка чугунных канализационных трубопроводов в траншеях',
            'Прокладка труб внутренней канализации',
            ['action' => 'pipe_layout', 'scope' => 'engineering', 'system' => 'sewerage'],
        ));
        self::assertTrue($service->isCompatible(
            'Прокладка чугунных канализационных трубопроводов в траншеях',
            'Прокладка наружной канализации в траншее',
            ['action' => 'pipe_layout', 'scope' => 'engineering', 'system' => 'sewerage'],
        ));
        self::assertTrue($service->isCompatible(
            'Прокладка трубопроводов внутренней канализации',
            'Прокладка труб внутренней канализации',
            ['action' => 'pipe_layout', 'scope' => 'engineering', 'system' => 'sewerage'],
        ));
    }
}
