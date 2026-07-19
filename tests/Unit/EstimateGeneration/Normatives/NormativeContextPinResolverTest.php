<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\DTO\WorkIntentData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractNormativeResourcePriceSelector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\AbstractResourceSemanticPriceSelector;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinResolver;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeContextPinSource;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeHardGate;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeIntentCandidateRanker;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeResourceRowData;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\PinnedNormativeCandidateFactory;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NormativeContextPinResolverTest extends TestCase
{
    #[Test]
    public function semantic_project_resource_selector_requires_pipe_material_polarity_and_exact_diameter(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Прокладка водопровода из стальных водогазопроводных оцинкованных труб диаметром 15 мм',
            'Трубопроводы с гильзами',
            'м',
            11,
            [
                $this->semanticPrice(901, '81.4.07.11', 'Труба ВГП стальная оцинкованная Ду 15 мм', '100', 11),
                $this->semanticPrice(902, '37.8.19.03', 'Оцинкованная стальная водогазопроводная труба DN15', '300', 11),
                $this->semanticPrice(903, '91.2.01.04', 'Труба стальная черная неоцинкованная 15 мм', '10', 11),
                $this->semanticPrice(904, '44.1.02.08', 'Труба стальная оцинкованная диаметром 20 мм', '20', 11),
                $this->semanticPrice(905, '55.6.03.02', 'Труба стальная оцинкованная для газопровода Ø15 мм', '30', 11),
                $this->semanticPrice(906, '63.2.08.10', 'Труба стальная оцинкованная 15 мм', '40', 11, 'кг'),
            ],
        );

        self::assertNotNull($selection);
        self::assertSame(901, $selection['row']->price_id);
        self::assertSame(2, $selection['candidates_count']);
        self::assertSame('regional_semantic_pipe_hard_attributes_median:v1', $selection['policy']);
    }

    #[Test]
    public function semantic_project_resource_selector_matches_non_galvanized_and_hdpe_wording_without_code_hints(): void
    {
        $selector = new AbstractResourceSemanticPriceSelector;
        $steel = $selector->select(
            'Монтаж отопления из стальных водогазопроводных неоцинкованных труб, диаметр 15 мм',
            'Трубопроводы с гильзами',
            'м',
            11,
            [
                $this->semanticPrice(911, '70.1.11.09', 'Черная стальная труба ВГП ДУ 15', '125', 11),
                $this->semanticPrice(912, '70.1.11.10', 'Стальная оцинкованная труба ДУ 15', '90', 11),
            ],
        );
        $hdpe = $selector->select(
            'Прокладка канализации из полиэтиленовых труб высокой плотности диаметром 50 мм',
            'Трубопроводы канализации с гильзами',
            'м',
            11,
            [
                $this->semanticPrice(921, '62.9.01.17', 'Канализационная труба ПНД DN 50', '75', 11),
                $this->semanticPrice(922, '62.9.01.18', 'Дренажная труба HDPE Ø50', '60', 11),
                $this->semanticPrice(923, '62.9.01.19', 'Труба ПНД питьевая напорная DN 50', '55', 11),
            ],
        );

        self::assertSame(911, $steel['row']->price_id ?? null);
        self::assertSame(921, $hdpe['row']->price_id ?? null);
    }

    #[Test]
    public function semantic_project_resource_selector_fails_closed_without_all_hard_attributes(): void
    {
        self::assertNull((new AbstractResourceSemanticPriceSelector)->select(
            'Прокладка трубопровода',
            'Трубы по проекту',
            'м',
            11,
            [$this->semanticPrice(931, '88.1.01.01', 'Труба стальная оцинкованная 15 мм', '100', 11)],
        ));
    }

    #[Test]
    public function semantic_project_resource_selector_uses_approved_base_pvc_window_outside_group_children(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Установка оконных блоков из ПВХ профилей двухстворчатых площадью проёма до 2 м2',
            'Блоки оконные пластиковые',
            'м2',
            11,
            [
                (object) [
                    'price_id' => 941, 'price_resource_code' => '09.4.03.01-1000',
                    'price_resource_name' => 'Блок оконный дерево-алюминиевый площадью до 1,5 м2',
                    'price_unit' => 'м2', 'base_price' => '7692.10', 'regional_price_version_id' => null,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
                (object) [
                    'price_id' => 942, 'price_resource_code' => '09.4.02.05-0042',
                    'price_resource_name' => 'Блок оконный из ПВХ профилей двухстворчатый',
                    'price_unit' => 'м2', 'base_price' => '11200.50', 'regional_price_version_id' => null,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
            ],
            [42],
        );

        self::assertSame(942, $selection['row']->price_id ?? null);
        self::assertSame('fsnb_semantic_hard_attributes_median:v4', $selection['policy'] ?? null);
    }

    #[Test]
    public function semantic_project_resource_selector_requires_exact_galvanized_duct_attributes(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Прокладка воздуховодов из листовой оцинкованной стали толщиной 0,5 мм, диаметром до 200 мм',
            'Воздуховоды металлические',
            'м2',
            11,
            [
                (object) [
                    'price_id' => 951, 'price_resource_code' => '19.1.01.02-0013',
                    'price_resource_name' => 'Воздуховод из листовой стали, толщина 1,0 мм, диаметр до 250 мм',
                    'price_unit' => 'м2', 'base_price' => '2040.98', 'regional_price_version_id' => null,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
                (object) [
                    'price_id' => 952, 'price_resource_code' => '19.1.01.01-0052',
                    'price_resource_name' => 'Воздуховод из листовой оцинкованной стали, толщина 0,5 мм, диаметр до 200 мм',
                    'price_unit' => 'м2', 'base_price' => '1380.25', 'regional_price_version_id' => null,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
            ],
            [42],
        );

        self::assertSame(952, $selection['row']->price_id ?? null);
        self::assertSame('fsnb_semantic_hard_attributes_median:v4', $selection['policy'] ?? null);
    }

    #[Test]
    public function semantic_window_selector_rejects_incompatible_leaf_count_and_opening_area(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Установка оконных блоков из ПВХ профилей поворотных (откидных, поворотно-откидных) двухстворчатых с площадью проема до 2 м2',
            'Блоки оконные пластиковые',
            'м2',
            11,
            [
                (object) [
                    'price_id' => 961, 'price_resource_code' => '11.3.02.04-0032',
                    'price_resource_name' => 'Блок оконный из ПВХ-профилей, трехстворчатый, площадь от 3,01 до 3,5 м2',
                    'price_unit' => 'м2', 'base_price' => '4000', 'regional_price_version_id' => 11,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
                (object) [
                    'price_id' => 962, 'price_resource_code' => '11.3.02.04-0021',
                    'price_resource_name' => 'Блок оконный из ПВХ-профилей, двустворчатый, с поворотно-откидной створкой, площадь до 2 м2',
                    'price_unit' => 'м2', 'base_price' => '5200', 'regional_price_version_id' => 11,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
                (object) [
                    'price_id' => 963, 'price_resource_code' => '11.3.02.04-0022',
                    'price_resource_name' => 'Блок оконный из ПВХ-профилей, двустворчатый, глухой, площадь до 2 м2',
                    'price_unit' => 'м2', 'base_price' => '4500', 'regional_price_version_id' => 11,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
            ],
            [42],
        );

        self::assertSame(962, $selection['row']->price_id ?? null);
        self::assertSame('regional_semantic_hard_attributes_median:v4', $selection['policy'] ?? null);
    }

    #[Test]
    public function semantic_duct_selector_rejects_fittings_for_a_straight_duct_norm(): void
    {
        $selection = (new AbstractResourceSemanticPriceSelector)->select(
            'Прокладка воздуховодов из листовой оцинкованной стали толщиной 0,5 мм, диаметром до 200 мм',
            'Воздуховоды металлические',
            'м2',
            11,
            [
                (object) [
                    'price_id' => 971, 'price_resource_code' => '19.1.01.09-0139',
                    'price_resource_name' => 'Изделия фасонные для воздуховодов из оцинкованной стали, толщина 0,5 мм, диаметр 125 мм',
                    'price_unit' => 'м2', 'base_price' => '700', 'regional_price_version_id' => 11,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
                (object) [
                    'price_id' => 972, 'price_resource_code' => '19.1.01.01-0052',
                    'price_resource_name' => 'Воздуховод из листовой оцинкованной стали, прямой участок, толщина 0,5 мм, диаметр до 200 мм',
                    'price_unit' => 'м2', 'base_price' => '1400', 'regional_price_version_id' => 11,
                    'dataset_version_id' => 42, 'price_dataset_source_type' => 'fsnb_2022',
                ],
            ],
            [42],
        );

        self::assertSame(972, $selection['row']->price_id ?? null);
        self::assertSame('regional_semantic_hard_attributes_median:v4', $selection['policy'] ?? null);
    }

    #[Test]
    public function semantic_project_resource_query_hints_bound_the_catalog_pool_by_hard_attributes(): void
    {
        self::assertSame([
            'material' => 'steel',
            'polarity' => 'galvanized',
            'diameter' => 15,
        ], (new AbstractResourceSemanticPriceSelector)->queryHints(
            'Прокладка водоснабжения из стальных оцинкованных труб диаметром 15 мм',
            'Трубопроводы с гильзами',
        ));
        self::assertNull((new AbstractResourceSemanticPriceSelector)->queryHints(
            'Прокладка трубопровода',
            'Трубы по проекту',
        ));
    }

    #[Test]
    public function abstract_resource_selector_uses_deterministic_lower_median_of_regional_children(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select('04.1.02.05', 11, [
            (object) ['price_id' => 4, 'price_resource_code' => '04.1.02.05-0004', 'base_price' => '900', 'regional_price_version_id' => 11],
            (object) ['price_id' => 2, 'price_resource_code' => '04.1.02.05-0002', 'base_price' => '300', 'regional_price_version_id' => 11],
            (object) ['price_id' => 3, 'price_resource_code' => '04.1.02.05-0003', 'base_price' => '700', 'regional_price_version_id' => 11],
            (object) ['price_id' => 1, 'price_resource_code' => '04.1.02.05-0001', 'base_price' => '100', 'regional_price_version_id' => 11],
            (object) ['price_id' => 5, 'price_resource_code' => '04.1.02.05-0005', 'base_price' => '500', 'regional_price_version_id' => null],
            (object) ['price_id' => 6, 'price_resource_code' => '04.1.02.06-0001', 'base_price' => '500', 'regional_price_version_id' => 11],
        ]);

        self::assertNotNull($selection);
        self::assertSame(2, $selection['row']->price_id);
        self::assertSame(4, $selection['candidates_count']);
    }

    #[Test]
    public function abstract_resource_selector_filters_exact_group_children_by_explicit_hard_attributes(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select(
            '24.3.02.05',
            11,
            [
                (object) [
                    'price_id' => 1,
                    'price_resource_code' => '24.3.02.05-0001',
                    'price_resource_name' => 'Труба напорная многослойная из полипропилена наружным диаметром 63 мм',
                    'base_price' => '100',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 2,
                    'price_resource_code' => '24.3.02.05-0002',
                    'price_resource_name' => 'Труба напорная многослойная из полипропилена наружным диаметром 20 мм',
                    'base_price' => '700',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 3,
                    'price_resource_code' => '24.3.02.05-0003',
                    'price_resource_name' => 'Труба стальная наружным диаметром 20 мм',
                    'base_price' => '200',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 4,
                    'price_resource_code' => '24.3.02.05-0004',
                    'price_resource_name' => 'Труба канализационная из полипропилена наружным диаметром 20 мм',
                    'base_price' => '50',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 5,
                    'price_resource_code' => '24.3.02.05-0005',
                    'price_resource_name' => 'Труба из полипропилена для водоснабжения и пожаротушения диаметром 20 мм',
                    'base_price' => '300',
                    'regional_price_version_id' => 11,
                ],
            ],
            [],
            'Прокладка трубопроводов водоснабжения из многослойных полипропиленовых труб диаметром 20 мм',
            'Трубы напорные многослойные из полипропилена номинальным наружным диаметром 20 мм',
        );

        self::assertNotNull($selection);
        self::assertSame(2, $selection['row']->price_id);
        self::assertSame(1, $selection['candidates_count']);
        self::assertSame('regional_child_hard_attributes_median:v2', $selection['policy']);
    }

    #[Test]
    public function abstract_window_resource_selector_rejects_a_different_frame_material(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select(
            '09.4.03.01',
            11,
            [
                (object) [
                    'price_id' => 1,
                    'price_resource_code' => '09.4.03.01-0001',
                    'price_resource_name' => 'Блок оконный дерево-алюминиевый с двойным остеклением',
                    'base_price' => '9000',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 2,
                    'price_resource_code' => '09.4.03.01-0002',
                    'price_resource_name' => 'Блок оконный пластиковый двухстворчатый',
                    'base_price' => '7000',
                    'regional_price_version_id' => 11,
                ],
            ],
            [],
            'Установка оконных блоков из ПВХ профилей двухстворчатых',
            'Блоки оконные',
        );

        self::assertSame(2, $selection['row']->price_id ?? null);
        self::assertSame(1, $selection['candidates_count'] ?? null);
        self::assertSame('regional_child_hard_attributes_median:v2', $selection['policy'] ?? null);
    }

    #[Test]
    public function abstract_duct_resource_selector_matches_material_thickness_and_diameter_limit(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select(
            '19.1.01.02',
            11,
            [
                (object) [
                    'price_id' => 1,
                    'price_resource_code' => '19.1.01.02-0001',
                    'price_resource_name' => 'Воздуховоды из листовой оцинкованной стали толщиной 0,5 мм, диаметром до 200 мм',
                    'base_price' => '1200',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 2,
                    'price_resource_code' => '19.1.01.02-0002',
                    'price_resource_name' => 'Воздуховоды из листовой стали толщиной 2,0 мм, диаметром до 560 мм',
                    'base_price' => '2800',
                    'regional_price_version_id' => 11,
                ],
            ],
            [],
            'Прокладка воздуховодов из листовой оцинкованной стали и алюминия толщиной 0,5 мм, диаметром до 200 мм',
            'Воздуховоды из листовой стали',
        );

        self::assertSame(1, $selection['row']->price_id ?? null);
        self::assertSame(1, $selection['candidates_count'] ?? null);
        self::assertSame('regional_child_hard_attributes_median:v2', $selection['policy'] ?? null);
    }

    #[Test]
    public function abstract_window_and_duct_resource_selection_fails_closed_without_compatible_child(): void
    {
        $selector = new AbstractNormativeResourcePriceSelector;

        self::assertNull($selector->select(
            '09.4.03.01',
            11,
            [(object) [
                'price_id' => 1,
                'price_resource_code' => '09.4.03.01-0001',
                'price_resource_name' => 'Блок оконный дерево-алюминиевый',
                'base_price' => '9000',
                'regional_price_version_id' => 11,
            ]],
            [],
            'Установка оконных блоков из ПВХ профилей',
            'Блоки оконные',
        ));
        self::assertNull($selector->select(
            '19.1.01.02',
            11,
            [(object) [
                'price_id' => 2,
                'price_resource_code' => '19.1.01.02-0002',
                'price_resource_name' => 'Воздуховоды из листовой стали толщиной 2,0 мм, диаметром до 560 мм',
                'base_price' => '2800',
                'regional_price_version_id' => 11,
            ]],
            [],
            'Прокладка воздуховодов из листовой оцинкованной стали толщиной 0,5 мм, диаметром до 200 мм',
            'Воздуховоды из листовой стали',
        ));
    }

    #[Test]
    public function abstract_resource_selector_fails_closed_when_explicit_hard_attributes_have_no_match(): void
    {
        self::assertNull((new AbstractNormativeResourcePriceSelector)->select(
            '24.3.02.05',
            11,
            [(object) [
                'price_id' => 1,
                'price_resource_code' => '24.3.02.05-0001',
                'price_resource_name' => 'Труба напорная многослойная из полипропилена наружным диаметром 63 мм',
                'base_price' => '100',
                'regional_price_version_id' => 11,
            ]],
            [],
            'Прокладка трубопроводов водоснабжения из полипропиленовых труб диаметром 20 мм',
            'Трубы напорные многослойные из полипропилена диаметром 20 мм',
        ));
    }

    #[Test]
    public function abstract_clamp_group_does_not_inherit_pipe_material_and_diameter_from_norm_title(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select(
            '24.1.02.01',
            11,
            [(object) [
                'price_id' => 1,
                'price_resource_code' => '24.1.02.01-0001',
                'price_resource_name' => 'Хомут стальной для крепления труб',
                'base_price' => '320',
                'regional_price_version_id' => 11,
            ]],
            [],
            'Прокладка трубопроводов из полипропиленовых труб наружным диаметром 20 мм',
            'Хомуты для крепления труб',
        );

        self::assertSame(1, $selection['row']->price_id ?? null);
        self::assertSame('regional_child_median:v1', $selection['policy'] ?? null);
    }

    #[Test]
    public function abstract_pipe_group_inherits_pipe_attributes_from_norm_title(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select(
            '24.3.02.05',
            11,
            [
                (object) [
                    'price_id' => 1,
                    'price_resource_code' => '24.3.02.05-0001',
                    'price_resource_name' => 'Труба стальная наружным диаметром 25 мм',
                    'base_price' => '100',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 2,
                    'price_resource_code' => '24.3.02.05-0002',
                    'price_resource_name' => 'Труба напорная из полипропилена наружным диаметром 20 мм',
                    'base_price' => '700',
                    'regional_price_version_id' => 11,
                ],
            ],
            [],
            'Прокладка трубопроводов из полипропиленовых труб наружным диаметром 20 мм',
            'Трубы напорные',
        );

        self::assertSame(2, $selection['row']->price_id ?? null);
        self::assertSame('regional_child_hard_attributes_median:v2', $selection['policy'] ?? null);
    }

    #[Test]
    public function abstract_resource_selector_recognizes_nominal_bore_as_a_hard_diameter(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select(
            '23.3.06.01',
            11,
            [
                (object) [
                    'price_id' => 1,
                    'price_resource_code' => '23.3.06.01-0001',
                    'price_resource_name' => 'Труба стальная оцинкованная с условным проходом 20 мм',
                    'base_price' => '100',
                    'regional_price_version_id' => 11,
                ],
                (object) [
                    'price_id' => 2,
                    'price_resource_code' => '23.3.06.01-0002',
                    'price_resource_name' => 'Труба стальная оцинкованная с условным проходом 15 мм',
                    'base_price' => '200',
                    'regional_price_version_id' => 11,
                ],
            ],
            [],
            'Прокладка водопровода из оцинкованных стальных труб с условным проходом 15 мм',
            'Трубы стальные оцинкованные',
        );

        self::assertSame(2, $selection['row']->price_id ?? null);
        self::assertSame(1, $selection['candidates_count'] ?? null);
    }

    #[Test]
    public function abstract_resource_selector_fails_closed_for_conflicting_target_or_candidate_diameters(): void
    {
        $candidate = (object) [
            'price_id' => 1,
            'price_resource_code' => '23.3.06.01-0001',
            'price_resource_name' => 'Труба стальная оцинкованная диаметром 15 мм',
            'base_price' => '100',
            'regional_price_version_id' => 11,
        ];
        $selector = new AbstractNormativeResourcePriceSelector;

        self::assertNull($selector->select(
            '23.3.06.01',
            11,
            [$candidate],
            [],
            'Прокладка водопровода из стальных труб диаметром 15 мм',
            'Трубы стальные диаметром 20 мм',
        ));
        self::assertNull($selector->select(
            '23.3.06.01',
            11,
            [(object) [
                ...((array) $candidate),
                'price_resource_name' => 'Труба стальная диаметром 15 мм с условным проходом 20 мм',
            ]],
            [],
            'Прокладка водопровода из стальных труб диаметром 15 мм',
            'Трубы стальные',
        ));
    }

    #[Test]
    public function abstract_resource_selector_uses_explicit_base_catalog_fallback_when_regional_children_are_absent(): void
    {
        $selection = (new AbstractNormativeResourcePriceSelector)->select('04.1.02.05', 11, [
            (object) ['price_id' => 1, 'dataset_version_id' => 154, 'price_dataset_source_type' => 'fsbc', 'price_resource_code' => '04.1.02.05-0001', 'base_price' => '500', 'regional_price_version_id' => null],
            (object) ['price_id' => 2, 'dataset_version_id' => 154, 'price_dataset_source_type' => 'fsbc', 'price_resource_code' => '04.1.02.05-0002', 'base_price' => '900', 'regional_price_version_id' => null],
        ], [154]);

        self::assertNotNull($selection);
        self::assertSame(1, $selection['row']->price_id);
        self::assertSame('fsbc_base_child_median:v1', $selection['policy']);
    }

    #[Test]
    public function exact_catalog_and_regional_price_identity_is_resolved_by_production_source_contract(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public int $calls = 0;

            public array $intents = [];

            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $this->calls++;
                $this->intents = $intents;

                return $requested->datasetVersion === 'fsnb-2026.1' && $requested->priceVersion === 'prices-2026.07'
                    ? new NormativeContextPinData(
                        $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                        $requested->regionId, $requested->priceZoneId, $requested->periodId,
                        $requested->regionalPriceVersionId, $requested->priceVersion,
                        [['candidate_id' => '101']], str_repeat('a', 64),
                    )
                    : null;
            }
        };
        $resolver = new NormativeContextPinResolver($source);
        $context = [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'year' => 2026, 'quarter' => 3,
        ];

        $intents = [['search_text' => 'РњРѕРЅС‚Р°Р¶ РєРёСЂРїРёС‡РЅС‹С… СЃС‚РµРЅ', 'unit' => 'm2', 'code' => null]];
        $scenario = (new \App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog)
            ->issue('walls.external_volume', 'residential');
        self::assertIsArray($scenario);
        $intents[0]['specialization_scenario'] = $scenario;
        $intents[0]['specialization_evidence'] = [[
            'text' => 'Материал стены подтверждён чертежом',
            'source' => 'document',
            'evidence_refs' => ['doc:1'],
        ]];
        $pin = $resolver->resolve($context, $intents);

        self::assertSame('pinned', $pin['status']);
        self::assertSame(77, $pin['dataset_id']);
        self::assertSame(11, $pin['regional_price_version_id']);
        self::assertSame([['candidate_id' => '101']], $pin['catalog_candidates']);
        self::assertSame('2026-07-01', $pin['applicability_date']);
        self::assertSame($pin, $resolver->resolve($context, $intents));
        self::assertSame(2, $source->calls);
        self::assertSame($intents, $source->intents);
    }

    #[Test]
    public function incomplete_or_inconsistent_resource_context_fails_closed(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                return null;
            }
        };
        $resolver = new NormativeContextPinResolver($source);

        self::assertSame('normative_resource_context_not_pinned', $resolver->resolve([
            'normative_dataset_version' => 'fsnb-2026.1', 'business_date' => '2026-07-01',
        ])['blocking_issues'][0]);
        self::assertSame('normative_resource_context_not_approved', $resolver->resolve([
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'wrong',
            'business_date' => '2026-07-01',
        ], [['search_text' => 'РњРѕРЅС‚Р°Р¶ СЃС‚РµРЅС‹', 'unit' => 'm2']])['blocking_issues'][0]);
        self::assertSame('normative_work_intents_not_pinned', $resolver->resolve([
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-01',
        ], [])['blocking_issues'][0]);
    }

    #[Test]
    public function unique_intent_limit_is_checked_before_source_query_while_exact_limit_is_bounded(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public int $calls = 0;

            public int $received = 0;

            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $this->calls++;
                $this->received = count($intents);

                return new NormativeContextPinData(
                    $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                    $requested->regionId, $requested->priceZoneId, $requested->periodId,
                    $requested->regionalPriceVersionId, $requested->priceVersion,
                    [['candidate_id' => '101']], str_repeat('a', 64),
                );
            }
        };
        $resolver = new NormativeContextPinResolver($source);
        $context = [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-01',
        ];
        $intents = array_map(
            static fn (int $index): array => ['search_text' => 'intent-'.$index, 'unit' => 'm2'],
            range(1, 65),
        );

        self::assertSame('normative_work_intents_limit_exceeded', $resolver->resolve($context, $intents)['blocking_issues'][0]);
        self::assertSame(0, $source->calls);
        self::assertSame('pinned', $resolver->resolve($context, array_slice($intents, 0, 64))['status']);
        self::assertSame(1, $source->calls);
        self::assertSame(64, $source->received);
    }

    #[Test]
    public function structured_work_context_is_preserved_and_participates_in_deduplication(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public array $intents = [];

            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $this->intents = $intents;

                return new NormativeContextPinData(
                    $requested->datasetId, $requested->datasetVersion, $requested->applicabilityDate,
                    $requested->regionId, $requested->priceZoneId, $requested->periodId,
                    $requested->regionalPriceVersionId, $requested->priceVersion,
                    [['candidate_id' => '101']], str_repeat('a', 64),
                );
            }
        };
        $resolver = new NormativeContextPinResolver($source);
        $context = [
            'normative_dataset_id' => 77, 'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16, 'price_zone_id' => 3, 'period_id' => 8,
            'estimate_regional_price_version_id' => 11, 'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-01',
        ];

        $pin = $resolver->resolve($context, [
            [
                'search_text' => 'Монтаж блоков', 'unit' => 'pcs', 'action' => 'window_installation',
                'scope' => 'openings', 'system' => null, 'object' => 'window', 'object_type' => 'house',
            ],
            [
                'search_text' => 'Монтаж блоков', 'unit' => 'pcs', 'action' => 'window_installation',
                'scope' => 'openings', 'system' => null, 'object' => 'door', 'object_type' => 'house',
            ],
        ]);

        self::assertSame('pinned', $pin['status']);
        self::assertCount(2, $source->intents);
        self::assertSame('window', $source->intents[0]['object']);
        self::assertSame('door', $source->intents[1]['object']);
        self::assertSame('openings', $source->intents[0]['scope']);
        self::assertSame('house', $source->intents[0]['object_type']);
    }

    #[Test]
    public function resolved_material_without_source_evidence_does_not_confirm_specialized_norm(): void
    {
        $source = new class implements NormativeContextPinSource
        {
            public function resolveForIntents(NormativeContextPinData $requested, array $intents): ?NormativeContextPinData
            {
                $candidate = (object) [
                    'id' => 150106401,
                    'code' => '15-01-064-01',
                    'name' => 'Облицовка фасадов фиброцементными плитами',
                    'canonical_unit' => '100 m2',
                    'unit' => '100 m2',
                    'section_code' => '15-01',
                    'section_name' => 'Отделочные работы',
                    'work_composition' => [],
                ];
                $selected = (new NormativeIntentCandidateRanker)->select([$candidate], $intents);
                if ($selected === null) {
                    return null;
                }

                return new NormativeContextPinData(
                    $requested->datasetId,
                    $requested->datasetVersion,
                    $requested->applicabilityDate,
                    $requested->regionId,
                    $requested->priceZoneId,
                    $requested->periodId,
                    $requested->regionalPriceVersionId,
                    $requested->priceVersion,
                    [['candidate_id' => (string) $selected[0]->id]],
                    str_repeat('a', 64),
                );
            }
        };
        $resolver = new NormativeContextPinResolver($source);

        $pin = $resolver->resolve([
            'normative_dataset_id' => 77,
            'normative_dataset_version' => 'fsnb-2026.1',
            'region_id' => 16,
            'price_zone_id' => 3,
            'period_id' => 8,
            'estimate_regional_price_version_id' => 11,
            'price_version' => 'prices-2026.07',
            'business_date' => '2026-07-01',
        ], [[
            'search_text' => 'Отделка фасада',
            'unit' => 'm2',
            'material' => 'fiber_cement',
            'action' => 'general_work',
            'scope' => 'facade',
            'object_type' => 'residential',
            'normative_sections' => ['15'],
        ]]);

        self::assertSame('review_required', $pin['status']);
        self::assertNull($pin['catalog_candidates']);
    }

    #[Test]
    public function production_source_keeps_norm_dataset_exact_and_combines_authoritative_base_prices(): void
    {
        $source = file_get_contents(dirname(__DIR__, 4).'/app/BusinessModules/Addons/EstimateGeneration/Normatives/Services/EloquentNormativeContextPinSource.php');

        self::assertStringNotContainsString('latest(', $source);
        self::assertStringNotContainsString('first(', $source);
        self::assertStringNotContainsString("->orderByDesc('norms.id')", $source);
        self::assertStringContainsString("->where('id', \$requested->datasetId)", $source);
        self::assertStringContainsString("->where('prices.regional_price_version_id', \$requested->regionalPriceVersionId)", $source);
        self::assertStringContainsString("->where('status', 'active')", $source);
        self::assertStringContainsString('->whereExists(function ($priced) use ($requested, $basePriceDatasetIds)', $source);
        self::assertStringContainsString("->where('pin_resources.quantity', '>', 0)", $source);
        self::assertStringContainsString("->where('pin_prices.base_price', '>', 0)", $source);
        self::assertStringNotContainsString("->whereColumn('pin_resources.construction_resource_id', 'pin_prices.construction_resource_id')", $source);
        self::assertStringNotContainsString("->on('pin_prices.price_type', '=', 'pin_resources.resource_type')", $source);
        self::assertStringContainsString('->whereNotExists(function ($unpriced) use ($requested, $basePriceDatasetIds)', $source);
        self::assertStringContainsString('->whereNotExists(function ($validPrice) use ($requested, $basePriceDatasetIds)', $source);
        self::assertStringContainsString("->where('required_resources.quantity', '>', 0)", $source);
        self::assertStringContainsString("->where('required_resources.resource_type', '<>', 'summary')", $source);
        self::assertStringContainsString("required_resources.raw_payload->>'source_tag'", $source);
        self::assertStringContainsString("resources.raw_payload->>'source_tag'", $source);
        self::assertStringContainsString("raw_payload->>'source_tag'", $source);
        self::assertStringContainsString('unpriced_abstract_resources', $source);
        self::assertStringContainsString('->whereExists(function ($positiveQuantity)', $source);
        self::assertStringContainsString("->where('positive_resources.quantity', '>', 0)", $source);
        self::assertStringContainsString('->whereNotExists(function ($negativeQuantity)', $source);
        self::assertStringContainsString("->where('negative_resources.quantity', '<', 0)", $source);
        self::assertStringContainsString("->whereColumn('valid_prices.resource_code', 'required_resources.resource_code')", $source);
        self::assertStringContainsString('estimate_generation_unit_conversions as valid_conversions', $source);
        self::assertStringContainsString("->where('valid_conversions.version', 1)", $source);
        self::assertStringContainsString("->where('valid_conversions.is_active', true)", $source);
        self::assertStringContainsString("->where('valid_conversions.factor', '>', 0)", $source);
        self::assertStringContainsString('pin_prices.unit IS NOT DISTINCT FROM pin_resources.unit', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(pin_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString('valid_prices.unit IS NOT DISTINCT FROM required_resources.unit', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(valid_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString('candidate_prices.unit IS NOT DISTINCT FROM resources.unit', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(candidate_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString("table('estimate_resource_prices as semantic_project_prices')", $source);
        self::assertStringContainsString("->whereIn('semantic_project_prices.unit', \$semanticRequiredUnits)", $source);
        self::assertStringContainsString("->where('semantic_project_prices.regional_price_version_id', \$requested->regionalPriceVersionId)", $source);
        self::assertStringContainsString('->limit(5_001)', $source);
        self::assertStringContainsString('->unique(static fn (array $hint): string', $source);
        self::assertStringContainsString("'%'.\$hint['diameter'].'%'", $source);
        self::assertStringContainsString("'prices.unit as price_unit'", $source);
        self::assertStringContainsString("->where('resources.quantity', '>', 0)", $source);
        self::assertStringContainsString("->where('resources.resource_type', '<>', 'summary')", $source);
        self::assertStringContainsString("->where('quantity', '>', 0)", $source);
        self::assertStringContainsString('$this->ranker->select($query->all(), [$intent])', $source);
        self::assertStringContainsString("norms.search_vector @@ websearch_to_tsquery('russian', ?)", $source);
        self::assertStringContainsString("ts_rank_cd(norms.search_vector, websearch_to_tsquery('russian', ?)) AS pin_lexical_score", $source);
        self::assertStringContainsString("->orderByDesc('pin_lexical_score')", $source);
        self::assertStringContainsString('->limit(self::CANDIDATE_POOL_LIMIT)', $source);
        self::assertStringContainsString('CAST(norms.work_composition AS TEXT)', $source);
        self::assertStringContainsString("->where('source_type', 'fsnb_2022')", $source);
        self::assertStringContainsString("\$allowedSections->{\$method}('norms.section_code', 'like', \$section.'%')", $source);
        self::assertStringContainsString("latestPriceDatasetId('fsbc', true)", $source);
        self::assertStringContainsString("latestPriceDatasetId('fgis_labor_prices', false)", $source);
        self::assertStringContainsString('$fsbcBasePriceDatasetId,', $source);
        self::assertStringContainsString('$fgisLaborPriceDatasetId,', $source);
        self::assertStringContainsString('$requested->datasetId,', $source);
        self::assertStringContainsString("->whereIn('pin_prices.dataset_version_id', \$basePriceDatasetIds)", $source);
        self::assertStringContainsString("->whereIn('valid_prices.dataset_version_id', \$basePriceDatasetIds)", $source);
        self::assertStringContainsString('candidate_prices.dataset_version_id IN (', $source);
        self::assertStringContainsString('$basePricePlaceholders', $source);
        self::assertStringContainsString("->whereNull('regional_price_version_id')", $source);
        self::assertStringContainsString('basePriceDatasetIds', $source);
        self::assertStringContainsString('base_price_dataset_ids', $source);
        self::assertStringContainsString('code_matched_resource_rows_count', $source);
        self::assertStringContainsString('exact_unit_matched_resource_rows_count', $source);
        self::assertStringContainsString('normalized_unit_matched_resource_rows_count', $source);
        self::assertStringContainsString('diagnostic_lexical_candidates_count', $source);
        self::assertStringContainsString('unmatched_unit_pairs', $source);
        self::assertStringContainsString('diagnostic_pair_prices', $source);
        self::assertStringContainsString("telemetry('intent_preprice_candidates'", $source);
        self::assertStringContainsString('prePriceCandidateDiagnostics(', $source);
        self::assertStringContainsString('$this->priceCoverageAnalyzer->analyze(', $source);
        self::assertStringContainsString("REGEXP_REPLACE(COALESCE(diagnostic_normalized_prices.unit, ''), '[[:space:].,-]+', '', 'g')", $source);
        self::assertStringContainsString("'resources.id as norm_resource_id'", $source);
        self::assertStringContainsString('resolveForIntents', $source);
        self::assertStringContainsString('CANDIDATE_POOL_LIMIT = 300', $source);
        self::assertStringContainsString('markersForAction', $source);
        self::assertStringContainsString('pin_semantic_priority', $source);
        self::assertStringNotContainsString("->orderBy('norms.id')->limit(129)", $source);
        self::assertStringNotContainsString("->where('norms.canonical_unit', \$unit)", $source);
    }

    #[Test]
    public function ranker_selects_candidate_from_the_preferred_normative_section(): void
    {
        $candidates = [
            (object) ['id' => 1, 'code' => '08-01-001-01', 'name' => 'Устройство покрытий', 'canonical_unit' => 'm2', 'unit' => 'm2', 'section_code' => '08'],
            (object) ['id' => 2, 'code' => '11-01-001-01', 'name' => 'Устройство покрытий', 'canonical_unit' => 'm2', 'unit' => 'm2', 'section_code' => '11'],
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Устройство покрытий', 'unit' => 'm2', 'code' => null, 'normative_section' => '11',
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function ranker_accepts_a_relevant_candidate_from_any_allowed_section(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) ['id' => 1, 'code' => '09-01-001-01', 'name' => 'Установка лестничных маршей', 'canonical_unit' => '100 pcs', 'unit' => '100 pcs', 'section_code' => '09'],
            (object) ['id' => 2, 'code' => '07-01-001-01', 'name' => 'Установка лестничных маршей', 'canonical_unit' => '100 pcs', 'unit' => '100 pcs', 'section_code' => '07'],
        ], [[
            'search_text' => 'Установка лестничных маршей', 'unit' => 'pcs', 'code' => null,
            'normative_sections' => ['06', '07', '08'],
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function ranker_keeps_relevance_order_instead_of_catalog_identifier_order(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 1, 'code' => '08-01-001-01', 'name' => 'Устройство конструкций стен',
                'canonical_unit' => 'm3', 'unit' => 'm3', 'section_code' => '08',
            ],
            (object) [
                'id' => 200, 'code' => '08-01-002-01', 'name' => 'Кладка наружных стен из газобетонных блоков',
                'canonical_unit' => 'm3', 'unit' => 'm3', 'section_code' => '08',
            ],
        ], [[
            'search_text' => 'Кладка наружных стен из газобетонных блоков',
            'unit' => 'm3', 'code' => null, 'normative_section' => '08',
        ]]);

        self::assertSame([200, 1], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function ranker_excludes_semantically_foreign_candidates_before_bounding_the_pinned_pool(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 1, 'code' => '09-01-001-01',
                'name' => 'Прокладка заземляющего проводника по строительным основаниям',
                'canonical_unit' => 'm', 'unit' => 'm', 'section_code' => '09',
            ],
            (object) [
                'id' => 2, 'code' => '09-01-002-01',
                'name' => 'Устройство временного ограждения строительной площадки',
                'canonical_unit' => 'm', 'unit' => 'm', 'section_code' => '09',
            ],
        ], [[
            'search_text' => 'Временное ограждение строительной площадки',
            'unit' => 'm', 'code' => null, 'action' => 'fence_installation',
            'normative_section' => '09',
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function explicitly_requested_normative_code_cannot_bypass_automatic_semantic_filter(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 10, 'code' => '09-01-001-01', 'name' => 'Специальная проектная норма',
                'canonical_unit' => 'm', 'unit' => 'm', 'section_code' => '09',
            ],
        ], [[
            'search_text' => 'Устройство временного ограждения', 'unit' => 'm',
            'code' => '09-01-001-01', 'action' => 'fence_installation', 'normative_section' => '09',
        ]]);

        self::assertNull($selected);
    }

    #[Test]
    public function generic_finishing_word_cannot_match_wet_zone_tiling_to_facade_norm(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 1, 'code' => '15-02-009-01',
                'name' => 'Фактурная отделка фасадов стеклянной крошкой',
                'section_name' => 'Отделочные работы', 'section_code' => '15',
                'canonical_unit' => '100 m2', 'unit' => '100 m2',
            ],
            (object) [
                'id' => 2, 'code' => '15-01-019-05',
                'name' => 'Облицовка стен керамическими плитками',
                'section_name' => 'Отделочные работы', 'section_code' => '15',
                'canonical_unit' => '100 m2', 'unit' => '100 m2',
            ],
        ], [[
            'search_text' => 'Отделка мокрых зон плиткой',
            'unit' => 'm2', 'code' => null, 'normative_section' => '15',
        ]]);

        self::assertSame([2], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function exact_relevant_norm_above_first_128_is_selected_before_unrelated_candidates_are_bounded(): void
    {
        $candidates = [];
        for ($id = 1; $id <= 200; $id++) {
            $candidates[] = (object) [
                'id' => $id,
                'code' => '10-01-'.$id,
                'name' => $id === 199 ? 'РњРѕРЅС‚Р°Р¶ РЅРµСЃСѓС‰РµР№ СЃС‚РµРЅС‹' : 'РњРѕРЅС‚Р°Р¶ СЃС‚РµРЅС‹ РІР°СЂРёР°РЅС‚ '.$id,
                'canonical_unit' => 'm2',
                'unit' => 'm2',
            ];
        }
        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'РњРѕРЅС‚Р°Р¶ РЅРµСЃСѓС‰РµР№ СЃС‚РµРЅС‹', 'unit' => 'm2', 'code' => null,
        ]]);

        self::assertNotNull($selected);
        self::assertContains(199, array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
        self::assertLessThanOrEqual(16, count($selected));
        self::assertNull((new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'roof waterproofing', 'unit' => 'm2', 'code' => null,
        ]]));
    }

    #[Test]
    public function scaled_catalog_unit_is_compatible_with_work_item_unit(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 101,
            'code' => '01-01-006-01',
            'name' => 'Разработка грунта в котлованах',
            'canonical_unit' => '1000 м3',
            'unit' => '1000 м3',
        ]], [[
            'search_text' => 'Разработка грунта под фундаменты',
            'unit' => 'm3',
            'code' => null,
        ]]);

        self::assertNotNull($selected);
        self::assertSame([101], array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
    }

    #[Test]
    public function russian_stems_match_inflected_norm_name(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 202,
            'code' => '07-05-016-01',
            'name' => 'Монтаж лестничных маршей и площадок',
            'section_name' => 'Лестницы',
            'canonical_unit' => '100 шт',
            'unit' => '100 шт',
        ]], [[
            'search_text' => 'Устройство лестничных маршей',
            'unit' => 'шт',
            'code' => null,
        ]]);

        self::assertNotNull($selected);
        self::assertSame([202], array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
    }

    #[Test]
    public function candidate_composition_does_not_replace_title_for_strong_action(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 203,
            'code' => '16-02-001-01',
            'name' => 'Трубопровод стальной 219 мм',
            'work_composition' => ['Прокладка кабеля в защитной трубе'],
            'canonical_unit' => 'm',
            'unit' => 'm',
        ]], [[
            'search_text' => 'Прокладка кабельных линий',
            'unit' => 'm',
            'code' => null,
            'action' => 'cable_installation',
        ]]);

        self::assertNull($selected);
    }

    #[Test]
    public function residential_pipe_intent_rejects_industrial_diameter_before_pinning(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([
            (object) [
                'id' => 204,
                'code' => '16-02-005-08',
                'name' => 'Прокладка трубопроводов водоснабжения из стальных труб диаметром 200 мм',
                'canonical_unit' => '100 m',
                'unit' => '100 m',
                'section_code' => '16',
            ],
            (object) [
                'id' => 205,
                'code' => '16-02-005-01',
                'name' => 'Прокладка трубопроводов водоснабжения из стальных труб диаметром 32 мм',
                'canonical_unit' => '100 m',
                'unit' => '100 m',
                'section_code' => '16',
            ],
        ], [[
            'search_text' => 'Прокладка труб водоснабжения',
            'unit' => 'm',
            'code' => null,
            'action' => 'pipe_layout',
            'scope' => 'engineering',
            'system' => 'water_supply',
            'object_type' => 'house',
            'normative_section' => '16',
        ]]);

        self::assertSame([205], array_column($selected ?? [], 'id'));
    }

    #[Test]
    public function sixty_four_distinct_intents_keep_a_bounded_candidate_pool(): void
    {
        $candidates = [];
        $intents = [];
        for ($intent = 1; $intent <= 64; $intent++) {
            $intents[] = ['search_text' => 'intentcode'.$intent, 'unit' => 'm2', 'code' => null];
            for ($variant = 1; $variant <= 3; $variant++) {
                $candidates[] = (object) [
                    'id' => ($intent * 10) + $variant,
                    'code' => 'code-'.$intent.'-'.$variant,
                    'name' => 'intentcode'.$intent.' variant'.$variant,
                    'canonical_unit' => '100 м2',
                    'unit' => '100 м2',
                ];
            }
        }

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, $intents);

        self::assertNotNull($selected);
        self::assertLessThanOrEqual(128, count($selected));
    }

    #[Test]
    public function unavailable_intent_does_not_discard_candidates_for_supported_intents(): void
    {
        $selected = (new NormativeIntentCandidateRanker)->select([(object) [
            'id' => 101,
            'code' => '01-01-006-01',
            'name' => 'Разработка грунта в котлованах',
            'canonical_unit' => '1000 м3',
            'unit' => '1000 м3',
        ]], [
            ['search_text' => 'Разработка грунта под фундаменты', 'unit' => 'm3', 'code' => null],
            ['search_text' => 'Несуществующая специальная работа', 'unit' => 'компл', 'code' => null],
        ]);

        self::assertNotNull($selected);
        self::assertSame([101], array_map(static fn (object $candidate): int => (int) $candidate->id, $selected));
    }

    #[Test]
    public function pinned_candidate_preserves_object_type_for_residential_hard_gate(): void
    {
        $candidates = (new PinnedNormativeCandidateFactory)->forWorkItem([[
            'candidate_id' => '101', 'normative_id' => 101, 'dataset_id' => 77,
            'dataset_version' => 'v1', 'dataset_status' => 'parsed', 'code' => '20-01-001-01',
            'name' => 'Монтаж вентиляции офиса', 'unit' => 'м', 'section' => ['code' => '20'],
            'retrieval_metadata' => ['unit_dimension' => 'length', 'object_type' => 'office'],
        ]], ['name' => 'Монтаж вентиляции', 'normative_search_text' => 'Монтаж вентиляции', 'unit' => 'м']);

        self::assertSame('office', $candidates[0]->objectType);

        $intent = new WorkIntentData(
            1, 2, 3, 'work', 'Монтаж вентиляции', 'м', 'length', '', '', '', '',
            'residential', 'v1', 'parsed', null, new DateTimeImmutable('2026-07-01'), [],
        );
        $result = (new NormativeHardGate)->filter($intent, $candidates);

        self::assertSame([], $result->candidates);
        self::assertContains('object_type_mismatch', $result->rejected[0]->reasonCodes);
    }

    #[Test]
    public function pinned_candidate_factory_does_not_replace_a_missing_exact_code_with_a_similar_wrong_norm(): void
    {
        $candidates = (new PinnedNormativeCandidateFactory)->forWorkItem([[
            'candidate_id' => 'wrong-box', 'normative_id' => 15301, 'dataset_id' => 77,
            'dataset_version' => 'v1', 'dataset_status' => 'parsed', 'code' => '08-02-153-01',
            'name' => 'Короб со стойками и полками для прокладки кабелей до 35 кВ',
            'unit' => '100 м', 'section' => ['code' => '08'],
        ]], [
            'name' => 'Прокладка силовых линий',
            'normative_search_text' => 'прокладка проводов силовой сети в готовых каналах сечением до 6 мм2',
            'normative_rate_code' => '08-02-404-01',
            'unit' => 'м',
        ]);

        self::assertSame([], $candidates);
    }

    #[Test]
    public function database_resource_row_uses_the_authoritative_resource_code_relation(): void
    {
        $row = (object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => 501, 'price_construction_resource_id' => 502,
            'price_id' => 9001, 'resource_type' => 'material', 'resource_code' => '01.7.01',
            'price_resource_code' => '01.7.01', 'price_unit' => '100 pcs',
            'resource_name' => 'Кирпич', 'unit' => 'pcs', 'quantity' => 50,
            'unit_price' => '125.450000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
        ];
        $mapped = NormativeResourceRowData::fromDatabaseRow($row);

        self::assertSame(101, $mapped->estimateNormId);
        self::assertSame('materials', $mapped->group);
        self::assertSame(7001, $mapped->resource['norm_resource_id']);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertSame(501, $mapped->resource['linked_resource_id']);
        self::assertSame('100 pcs', $mapped->resource['price_unit']);
        self::assertSame('125.450000', $mapped->resource['unit_price']);
        self::assertSame('regional_catalog', $mapped->resource['price_source']);
        self::assertSame('regional-2026-q2', $mapped->resource['price_source_version']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normative_resource_price_relation_invalid');
        NormativeResourceRowData::fromDatabaseRow((object) [
            ...(array) $row,
            'price_resource_code' => '01.7.02',
        ]);
    }

    #[Test]
    public function abstract_resource_row_keeps_original_norm_resource_and_documents_selected_regional_child(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => 502,
            'price_id' => 9001, 'resource_type' => 'abstract',
            'resource_code' => '04.1.02.05', 'price_resource_code' => '04.1.02.05-0123',
            'resource_name' => 'Смеси бетонные по проекту', 'price_resource_name' => 'Бетон В25 П4 F150 W6',
            'unit' => 'м3', 'price_unit' => 'м3', 'quantity' => '1.020000',
            'unit_price' => '7450.250000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
            'raw_source_tag' => 'AbstractResource', 'project_resource_candidates_count' => 7,
        ]);

        self::assertSame(7001, $mapped->resource['norm_resource_id']);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertSame('04.1.02.05', $mapped->resource['code']);
        self::assertSame(502, $mapped->resource['linked_resource_id']);
        self::assertSame([
            'group_code' => '04.1.02.05',
            'selected_resource_code' => '04.1.02.05-0123',
            'selected_resource_name' => 'Бетон В25 П4 F150 W6',
            'price_source' => 'regional_catalog',
            'price_source_version' => 'regional-2026-q2',
            'policy' => 'regional_child_median:v1',
            'candidates_count' => 7,
        ], $mapped->resource['project_resource_selection']);
    }

    #[Test]
    public function abstract_resource_row_preserves_exact_group_hard_attribute_policy(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101,
            'norm_resource_id' => 7001,
            'construction_resource_id' => null,
            'price_construction_resource_id' => 502,
            'price_id' => 9001,
            'resource_type' => 'material',
            'resource_code' => '24.3.02.05',
            'price_resource_code' => '24.3.02.05-0002',
            'resource_name' => 'Трубы напорные многослойные из полипропилена диаметром 20 мм',
            'price_resource_name' => 'Труба напорная многослойная из полипропилена диаметром 20 мм',
            'unit' => 'м',
            'price_unit' => 'м',
            'quantity' => '100.000000',
            'unit_price' => '145.500000',
            'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null,
            'price_dataset_version' => null,
            'raw_source_tag' => 'AbstractResource',
            'project_resource_candidates_count' => 1,
            'project_resource_price_policy' => 'regional_child_hard_attributes_median:v2',
        ]);

        self::assertSame(
            'regional_child_hard_attributes_median:v2',
            $mapped->resource['project_resource_selection']['policy'],
        );
    }

    #[Test]
    public function abstract_resource_row_rejects_policy_that_does_not_match_price_source(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normative_resource_price_relation_invalid');

        NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101,
            'norm_resource_id' => 7001,
            'price_id' => 9001,
            'resource_type' => 'material',
            'resource_code' => '24.3.02.05',
            'price_resource_code' => '24.3.02.05-0002',
            'resource_name' => 'Трубы из полипропилена диаметром 20 мм',
            'price_resource_name' => 'Труба из полипропилена диаметром 20 мм',
            'unit' => 'м',
            'quantity' => '100.000000',
            'unit_price' => '145.500000',
            'regional_price_version_id' => null,
            'price_dataset_source_type' => 'fsbc',
            'price_dataset_version' => 'fsbc-2026',
            'raw_source_tag' => 'AbstractResource',
            'project_resource_candidates_count' => 1,
            'project_resource_price_policy' => 'regional_child_hard_attributes_median:v1',
        ]);
    }

    #[Test]
    public function abstract_resource_row_rejects_child_outside_the_exact_group(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('normative_resource_price_relation_invalid');

        NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => 502,
            'price_id' => 9001, 'resource_type' => 'abstract',
            'resource_code' => '04.1.02.05', 'price_resource_code' => '04.1.02.06-0123',
            'resource_name' => 'Смеси бетонные по проекту', 'price_resource_name' => 'Чужой бетон',
            'unit' => 'м3', 'price_unit' => 'м3', 'quantity' => '1.020000',
            'unit_price' => '7450.250000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
            'raw_source_tag' => 'AbstractResource', 'project_resource_candidates_count' => 1,
        ]);
    }

    #[Test]
    public function semantic_abstract_resource_row_keeps_group_identity_and_real_regional_price(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => 502,
            'price_id' => 9001, 'resource_type' => 'material',
            'resource_code' => '18.2.07.01', 'price_resource_code' => '73.9.44.08',
            'resource_name' => 'Трубопроводы с гильзами',
            'price_resource_name' => 'Труба ВГП стальная оцинкованная Ду 15',
            'unit' => 'м', 'price_unit' => 'м', 'quantity' => '100.000000',
            'unit_price' => '245.500000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
            'raw_source_tag' => 'AbstractResource', 'project_resource_candidates_count' => 3,
            'project_resource_price_policy' => 'regional_semantic_pipe_hard_attributes_median:v1',
        ]);

        self::assertSame('18.2.07.01', $mapped->resource['code']);
        self::assertSame(7001, $mapped->resource['norm_resource_id']);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertSame(502, $mapped->resource['linked_resource_id']);
        self::assertSame('73.9.44.08', $mapped->resource['project_resource_selection']['selected_resource_code']);
        self::assertSame(
            'regional_semantic_pipe_hard_attributes_median:v1',
            $mapped->resource['project_resource_selection']['policy'],
        );
    }

    #[Test]
    public function exact_resource_code_links_norm_resource_to_regional_price_without_internal_resource_id(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => null,
            'price_id' => 9001, 'resource_type' => 'material',
            'resource_code' => '01.7.01', 'price_resource_code' => '01.7.01',
            'resource_name' => 'Кирпич', 'unit' => 'шт', 'quantity' => 50,
            'unit_price' => '125.450000', 'regional_price_version_id' => 11,
            'regional_price_version_key' => 'regional-2026-q2',
            'price_dataset_source_type' => null, 'price_dataset_version' => null,
        ]);

        self::assertSame(101, $mapped->estimateNormId);
        self::assertSame(9001, $mapped->resource['price_id']);
        self::assertNull($mapped->resource['linked_resource_id']);
    }

    #[Test]
    public function database_resource_row_preserves_fsnb_base_price_and_source(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => null,
            'price_id' => 9001, 'resource_type' => 'labor',
            'resource_code' => '1-100-01', 'price_resource_code' => '1-100-01',
            'resource_name' => 'Рабочий', 'unit' => 'чел.-ч', 'price_unit' => 'чел.-ч',
            'quantity' => '2.500000', 'unit_price' => '412.370000',
            'regional_price_version_id' => null, 'regional_price_version_key' => null,
            'price_dataset_source_type' => 'fsnb_2022', 'price_dataset_version' => '2022.4',
        ]);

        self::assertSame('412.370000', $mapped->resource['unit_price']);
        self::assertSame('fsnb_base', $mapped->resource['price_source']);
        self::assertSame('2022.4', $mapped->resource['price_source_version']);
    }

    #[Test]
    public function database_resource_row_preserves_fgis_labor_price_and_source(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => null,
            'price_id' => 9001, 'resource_type' => 'labor',
            'resource_code' => '1-100-01', 'price_resource_code' => '1-100-01',
            'resource_name' => 'Рабочий', 'unit' => 'чел.-ч', 'price_unit' => 'чел.-ч',
            'quantity' => '2.500000', 'unit_price' => '412.370000',
            'regional_price_version_id' => null, 'regional_price_version_key' => null,
            'price_dataset_source_type' => 'fgis_labor_prices', 'price_dataset_version' => '2026.2',
        ]);

        self::assertSame('fgis_labor_base', $mapped->resource['price_source']);
        self::assertSame('2026.2', $mapped->resource['price_source_version']);
    }

    #[Test]
    public function database_resource_row_accepts_semantically_selected_base_project_resource(): void
    {
        $mapped = NormativeResourceRowData::fromDatabaseRow((object) [
            'estimate_norm_id' => 101, 'norm_resource_id' => 7001,
            'construction_resource_id' => null, 'price_construction_resource_id' => 812,
            'price_id' => 9001, 'resource_type' => 'material',
            'resource_code' => '09.4.03.01', 'price_resource_code' => '09.4.02.05-0042',
            'resource_name' => 'Блоки оконные пластиковые',
            'price_resource_name' => 'Блок оконный из ПВХ профилей двухстворчатый',
            'unit' => 'м2', 'price_unit' => 'м2', 'quantity' => '100.000000', 'unit_price' => '11200.500000',
            'regional_price_version_id' => null, 'regional_price_version_key' => null,
            'price_dataset_source_type' => 'fsnb_2022', 'price_dataset_version' => '2026-05-07',
            'raw_source_tag' => 'AbstractResource', 'project_resource_candidates_count' => 2,
            'project_resource_price_policy' => 'fsnb_semantic_hard_attributes_median:v4',
        ]);

        self::assertSame('fsnb_base', $mapped->resource['price_source']);
        self::assertSame('09.4.02.05-0042', $mapped->resource['project_resource_selection']['selected_resource_code'] ?? null);
        self::assertSame('fsnb_semantic_hard_attributes_median:v4', $mapped->resource['project_resource_selection']['policy'] ?? null);
    }

    private function semanticPrice(
        int $priceId,
        string $code,
        string $name,
        string $price,
        int $regionalPriceVersionId,
        string $unit = 'м',
    ): object {
        return (object) [
            'price_id' => $priceId,
            'price_resource_code' => $code,
            'price_resource_name' => $name,
            'price_unit' => $unit,
            'base_price' => $price,
            'regional_price_version_id' => $regionalPriceVersionId,
        ];
    }
}
