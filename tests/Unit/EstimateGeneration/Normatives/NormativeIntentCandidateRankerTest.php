<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\NormativeIntentCandidateRanker;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialSignedNormCompatibility;
use PHPUnit\Framework\TestCase;

final class NormativeIntentCandidateRankerTest extends TestCase
{
    public function test_residential_sewerage_prefers_internal_pipeline_norm(): void
    {
        $candidates = [
            $this->candidate(
                401,
                '16-04-001-01',
                'Прокладка трубопроводов канализации из полиэтиленовых труб высокой плотности диаметром: 50 мм',
            ),
            $this->candidate(
                404,
                '16-04-004-01',
                'Прокладка внутренних трубопроводов канализации из полипропиленовых труб диаметром: 50 мм',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Прокладка труб канализации',
            'unit' => 'm',
            'action' => 'pipe_layout',
            'scope' => 'engineering',
            'system' => 'sewerage',
            'object_type' => 'residential',
            'normative_sections' => ['16'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame(404, $selected[0]->id);
    }

    public function test_warehouse_does_not_receive_residential_internal_pipeline_priority(): void
    {
        $candidates = [
            $this->candidate(401, '16-04-001-01', 'Прокладка трубопроводов канализации диаметром: 50 мм'),
            $this->candidate(404, '16-04-004-01', 'Прокладка внутренних трубопроводов канализации диаметром: 50 мм'),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Прокладка труб канализации',
            'unit' => 'm',
            'action' => 'pipe_layout',
            'scope' => 'engineering',
            'system' => 'sewerage',
            'object_type' => 'mixed_warehouse_office',
            'normative_sections' => ['16'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame(401, $selected[0]->id);
    }

    public function test_residential_candidates_exclude_wrong_specialized_objects(): void
    {
        $candidates = [
            $this->candidate(
                120104501,
                '12-01-045-01',
                'Устройство козырьков на металлических кронштейнах с покрытием кровельной сталью',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                120102001,
                '12-01-020-01',
                'Устройство кровель из металлочерепицы по готовым прогонам',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                120101305,
                '12-01-013-05',
                'Устройство теплоизоляции кровли плитами насухо',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                120101601,
                '12-01-016-01',
                'Устройство выравнивающей цементной стяжки кровли',
                '100 m2',
                '12-01',
            ),
            $this->candidate(
                70700301,
                '07-07-003-01',
                'Устройство перегородок панельных в зданиях промышленных и сельскохозяйственных предприятий',
                '100 m2',
                '07-07',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Монтаж кровельного покрытия',
            'unit' => 'm2',
            'action' => 'general_work',
            'scope' => 'roof',
            'object_type' => 'residential',
            'normative_sections' => ['12'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame([120102001], array_column($selected, 'id'));
    }

    public function test_electrical_candidates_require_installation_of_requested_object(): void
    {
        $candidates = [
            $this->candidate(
                80215216,
                '08-02-152-16',
                'Блок кабельных конструкций из одинарных стоек из угловой стали, устанавливаемый на стене',
                '100 m',
                '08-02',
            ),
            $this->candidate(
                80240201,
                '08-02-402-01',
                'Кабель трех-пятижильный, прокладываемый по установленным конструкциям и лоткам',
                '100 m',
                '08-02',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Прокладка силовых кабельных линий',
            'unit' => 'm',
            'action' => 'cable_installation',
            'scope' => 'engineering',
            'system' => 'electrical',
            'object_type' => 'residential',
            'normative_sections' => ['08'],
        ]]);

        self::assertNotNull($selected);
        self::assertSame([80240201], array_column($selected, 'id'));
    }

    public function test_generic_residential_facade_prefers_plaster_and_paint_over_unconfirmed_cladding(): void
    {
        $candidates = [
            $this->candidate(
                150106401,
                '15-01-064-01',
                'Облицовка фасадов фиброцементными и хризотилцементными плитами',
                '100 m2',
                '15-01',
            ),
            $this->candidate(
                150102001,
                '15-02-001-01',
                'Оштукатуривание фасадов цементно-известковым раствором',
                '100 m2',
                '15-02',
            ),
            $this->candidate(
                150401001,
                '15-04-001-01',
                'Окраска фасадов водно-дисперсионными красками',
                '100 m2',
                '15-04',
            ),
        ];

        $selected = (new NormativeIntentCandidateRanker)->select($candidates, [[
            'search_text' => 'Отделка фасада',
            'unit' => 'm2',
            'action' => 'general_work',
            'scope' => 'facade',
            'object_type' => 'residential',
            'normative_sections' => ['15'],
        ]]);

        self::assertNotNull($selected);
        self::assertEqualsCanonicalizing([150102001, 150401001], array_column($selected, 'id'));
    }

    public function test_residential_facade_uses_explicit_material_from_structured_intent(): void
    {
        $candidate = $this->candidate(
            150106401,
            '15-01-064-01',
            'Облицовка фасадов фиброцементными плитами',
            '100 m2',
            '15-01',
        );
        $intent = [
            'search_text' => 'Отделка фасада',
            'unit' => 'm2',
            'action' => 'general_work',
            'scope' => 'facade',
            'object_type' => 'residential',
            'normative_sections' => ['15'],
        ];

        self::assertNull((new NormativeIntentCandidateRanker)->select([$candidate], [$intent]));

        $intent['material'] = 'фиброцементные плиты';
        $intent['specialization_evidence'] = [[
            'text' => 'Фасадные плиты: фиброцемент',
            'source' => 'document',
            'evidence_refs' => ['doc:1'],
        ]];
        self::assertSame(
            [150106401],
            array_column((new NormativeIntentCandidateRanker)->select([$candidate], [$intent]) ?? [], 'id'),
        );
    }

    public function test_exact_ventilation_scenario_code_never_falls_back_to_another_size(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('ventilation.air_exchange', 'residential');
        self::assertIsArray($scenario);
        $intent = [
            'search_text' => 'монтаж воздуховодов',
            'unit' => 'm2',
            'code' => '20-01-001-01',
            'action' => 'ventilation_installation',
            'scope' => 'engineering',
            'system' => 'ventilation',
            'object_type' => 'residential',
            'normative_sections' => ['20'],
            'specialization_scenario' => $scenario,
        ];
        $wrongSize = $this->candidate(
            200100102,
            '20-01-001-02',
            'Прокладка воздуховодов из листовой оцинкованной стали класса Н диаметром до 250 мм',
            '100 m2',
            '20-01',
        );

        self::assertNull((new NormativeIntentCandidateRanker)->select([$wrongSize], [$intent]));

        $exact = $this->candidate(
            200100101,
            '20-01-001-01',
            'Прокладка воздуховодов из листовой оцинкованной стали класса Н диаметром до 200 мм',
            '100 m2',
            '20-01',
        );
        self::assertSame(
            [200100101],
            array_column((new NormativeIntentCandidateRanker)->select([$wrongSize, $exact], [$intent]) ?? [], 'id'),
        );
    }

    public function test_exact_residential_material_scenario_norms_are_accepted(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $ranker = new NormativeIntentCandidateRanker;
        $cases = [
            ['foundation.waterproofing', 'm2', 'waterproofing', 'foundation', '08', 'Гидроизоляция стен, фундаментов: Гидроизоляция боковая обмазочная битумная в 2 слоя по выровненной поверхности бутовой кладки, кирпичу, бетону'],
            ['walls.external_volume', 'm3', 'masonry', 'walls', '08', 'Кладка стен из газобетонных блоков на клее без облицовки толщиной: 400 мм при высоте этажа до 4 м'],
            ['walls.internal', 'm2', 'masonry', 'walls', '08', 'Кладка перегородок из газобетонных блоков на клее толщиной: 100 мм при высоте этажа до 4 м'],
            ['walls.lintels', 'pcs', 'general_work', 'walls', '07', 'Укладка перемычек при наибольшей массе монтажных элементов в здании: до 5 т, масса перемычки до 0,7 т'],
            ['roof.insulation', 'm2', 'insulation', 'roof', '12', 'Утепление покрытий плитами: из минеральной ваты насухо'],
            ['roof.covering', 'm2', 'general_work', 'roof', '12', 'Устройство кровли из металлочерепицы по готовым прогонам: простая кровля'],
            ['finish.floor', 'm2', 'floor_covering', 'finishing', '11', 'Устройство покрытий: из досок ламинированных замковым способом'],
            ['finish.baseboard', 'm', 'baseboard_installation', 'finishing', '11', 'Устройство плинтусов поливинилхлоридных: на винтах самонарезающих'],
            ['stairs.flights', 'm2', 'general_work', 'stairs', '10', 'Устройство внутриквартирных лестниц без подшивки'],
            ['openings.windows', 'm2', 'window_installation', 'openings', '10', 'Установка в жилых и общественных зданиях оконных блоков из ПВХ профилей поворотно-откидных двухстворчатых площадью проема до 2 м2'],
            ['electrical.grounding', 'm', 'grounding_installation', 'engineering', '08', 'Заземлитель горизонтальный из стали круглой диаметром 12 мм'],
            ['sanitary.waterproofing', 'm2', 'waterproofing', 'finishing', '11', 'Устройство гидроизоляции обмазочной битумной мастикой в один слой толщиной 2 мм'],
            ['sanitary.tile', 'm2', 'tiling', 'finishing', '15', 'Гладкая облицовка стен керамическими плитками на клее из сухих смесей по кирпичу и бетону'],
            ['foundation.prep', 'm3', 'concreting', 'foundation', '06', 'Устройство бетонной подготовки и фундаментов общего назначения: Устройство бетонной подготовки'],
            ['sanitary.showers', 'pcs', 'sanitary_fixture_installation', 'engineering', '17', 'Установка кабин душевых: с пластиковыми поддонами'],
            ['rough.floor', 'm2', 'floor_preparation', 'finishing', '11', 'Устройство стяжек: цементных толщиной 20 мм'],
        ];

        foreach ($cases as $index => [$workItemKey, $unit, $action, $scope, $section, $candidateName]) {
            $scenario = $catalog->issue($workItemKey, 'residential');
            self::assertIsArray($scenario);
            $candidate = $this->candidate(
                9000 + $index,
                (string) $scenario['normative_rate_code'],
                $candidateName,
                '100 '.$unit,
                $section,
            );
            $selected = $ranker->select([$candidate], [[
                'search_text' => (string) $scenario['normative_search_text'],
                'unit' => $unit,
                'code' => (string) $scenario['normative_rate_code'],
                'action' => $action,
                'scope' => $scope,
                'object_type' => 'residential',
                'normative_sections' => [$section],
                'specialization_scenario' => $scenario,
            ]]);

            self::assertSame([9000 + $index], array_column($selected ?? [], 'id'), $workItemKey);
        }
    }

    public function test_signed_exact_scenario_rejects_a_foreign_title_with_the_same_catalog_code(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('foundation.prep', 'residential');
        self::assertIsArray($scenario);

        $candidate = $this->candidate(
            60100101,
            (string) $scenario['normative_rate_code'],
            'Холодильная установка с герметичным компрессором',
            '100 m3',
            '06-01',
        );

        self::assertNull((new NormativeIntentCandidateRanker)->select([$candidate], [[
            'search_text' => (string) $scenario['normative_search_text'],
            'unit' => 'm3',
            'code' => (string) $scenario['normative_rate_code'],
            'action' => 'concreting',
            'scope' => 'foundation',
            'object_type' => 'residential',
            'normative_sections' => ['06'],
            'specialization_scenario' => $scenario,
        ]]));
    }

    public function test_exact_heating_equipment_code_selects_piece_installation_and_rejects_concrete_collision(): void
    {
        $scenario = (new ResidentialMaterialScenarioCatalog)->issue('heating.unit', 'residential');
        self::assertIsArray($scenario);

        $validInstallation = $this->candidate(
            370100201,
            '37-01-002-01',
            'Монтаж сосудов и аппаратов без механизмов в помещении, масса сосудов и аппаратов: 0,03 т',
            'шт',
            '37-01-002',
        );
        $concreteCollision = $this->candidate(
            370100299,
            '37-01-002-01',
            'Укладка бетонной смеси кранами башенными грузоподъемностью 25 т в железобетонные блоки высотой до 5 м',
            '100 м3',
            '37-01-002',
        );

        $signedCompatibility = new ResidentialSignedNormCompatibility;
        self::assertTrue($signedCompatibility->matches(
            $scenario,
            'residential',
            (string) $validInstallation->code,
            (string) $validInstallation->name,
        ));
        self::assertFalse($signedCompatibility->matches(
            $scenario,
            'residential',
            (string) $concreteCollision->code,
            (string) $concreteCollision->name,
        ));

        $selected = (new NormativeIntentCandidateRanker)->select([
            $concreteCollision,
            $validInstallation,
        ], [[
            'search_text' => (string) $scenario['normative_search_text'],
            'unit' => 'pcs',
            'code' => '37-01-002-01',
            'action' => 'electric_boiler_installation_analog',
            'scope' => 'engineering',
            'system' => 'heating',
            'object_type' => 'residential',
            'normative_sections' => ['37'],
            'specialization_scenario' => $scenario,
        ]]);

        self::assertSame([370100201], array_column($selected ?? [], 'id'));
    }

    private function candidate(
        int $id,
        string $code,
        string $name,
        string $unit = '100 m',
        string $sectionCode = '16-04',
    ): object {
        return (object) [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'canonical_unit' => $unit,
            'unit' => $unit,
            'section_code' => $sectionCode,
            'section_name' => 'Трубопроводы из пластмассовых труб',
            'work_composition' => [],
        ];
    }
}
