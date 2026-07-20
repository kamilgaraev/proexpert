<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResidentialMaterialScenarioCatalogTest extends TestCase
{
    public function test_issues_and_validates_only_catalog_owned_residential_scenarios(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;

        $issued = $catalog->issue('finish.floor', 'residential');

        self::assertIsArray($issued);
        self::assertSame('residential_preliminary_common:v18', $issued['scenario_id']);
        self::assertSame('finish.floor', $issued['work_item_key']);
        self::assertSame(['ламинат', 'ламинированн'], $issued['material_markers']);
        self::assertNotSame('', $issued['signature']);
        self::assertSame($issued, $catalog->resolve($issued, 'finish.floor', 'residential'));
        self::assertNull($catalog->issue('finish.floor', 'warehouse'));
        self::assertNull($catalog->issue('unknown.work', 'residential'));
    }

    public function test_rejects_arbitrary_legacy_text_and_tampered_payloads(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $issued = $catalog->issue('finish.floor', 'residential');

        self::assertIsArray($issued);
        self::assertNull($catalog->resolve([
            'version' => 'residential_finish_material:v1',
            'text' => 'паркет',
        ], 'finish.floor', 'residential'));
        self::assertNull($catalog->resolve([
            ...$issued,
            'material_markers' => ['паркет'],
        ], 'finish.floor', 'residential'));
        self::assertNull($catalog->resolve($issued, 'finish.baseboard', 'residential'));

        self::assertNull($catalog->resolve([
            ...$issued,
            'version' => 'residential_material_scenario:v1',
            'scenario_id' => 'residential_preliminary_common:v1',
        ], 'finish.floor', 'residential'));
    }

    public function test_validation_is_stable_after_json_object_key_reordering(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $issued = $catalog->issue('finish.floor', 'residential');

        self::assertIsArray($issued);
        $reordered = json_decode(json_encode([
            'signature' => $issued['signature'],
            'assumption_code' => $issued['assumption_code'],
            'normative_rate_code' => $issued['normative_rate_code'],
            'normative_search_text' => $issued['normative_search_text'],
            'material_markers' => $issued['material_markers'],
            'object_type' => $issued['object_type'],
            'work_item_key' => $issued['work_item_key'],
            'scenario_id' => $issued['scenario_id'],
            'version' => $issued['version'],
        ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($issued, $catalog->resolve($reordered, 'finish.floor', 'residential'));
    }

    public function test_issues_catalog_owned_small_residential_duct_specialization(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;

        $issued = $catalog->issue('ventilation.air_exchange', 'residential');

        self::assertIsArray($issued);
        self::assertSame('20-01-001-01', $issued['normative_rate_code']);
        self::assertSame('Монтаж вытяжных воздуховодов жилого дома', $issued['work_item_name']);
        self::assertSame('монтаж воздуховодов', $issued['normative_search_text']);
        self::assertContains('листовой оцинкованной стали', $issued['material_markers']);
        self::assertContains('диаметром до 200 мм', $issued['material_markers']);
        self::assertSame(
            $issued,
            $catalog->resolve($issued, 'ventilation.air_exchange', 'residential'),
        );
    }

    public function test_issues_signed_intent_actions_for_residential_engineering_scenarios(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;

        self::assertSame('cable_installation', $catalog->issue('electrical.main_cable', 'residential')['intent_action'] ?? null);
        self::assertSame('cable_installation', $catalog->issue('electrical.power_lines', 'residential')['intent_action'] ?? null);
        self::assertSame('cable_installation', $catalog->issue('lighting.lines', 'residential')['intent_action'] ?? null);
        self::assertSame('electrical_panel_installation', $catalog->issue('electrical.panel', 'residential')['intent_action'] ?? null);
        self::assertSame('socket_installation', $catalog->issue('electrical.outlets', 'residential')['intent_action'] ?? null);
        self::assertSame('socket_installation', $catalog->issue('electrical.switches', 'residential')['intent_action'] ?? null);
        self::assertSame('lighting_fixture_installation', $catalog->issue('lighting.fixtures', 'residential')['intent_action'] ?? null);
        self::assertNull($catalog->issue('heating.unit', 'residential'));
        self::assertSame('heating_emitter_installation', $catalog->issue('heating.radiators', 'residential')['intent_action'] ?? null);
        self::assertSame('concreting', $catalog->issue('foundation.prep', 'residential')['intent_action'] ?? null);
        self::assertSame('sanitary_fixture_installation', $catalog->issue('sanitary.showers', 'residential')['intent_action'] ?? null);
        self::assertSame('sanitary_fixture_installation', $catalog->issue('sanitary.toilets', 'residential')['intent_action'] ?? null);
        self::assertSame('sanitary_fixture_installation', $catalog->issue('sanitary.washbasins', 'residential')['intent_action'] ?? null);
        self::assertSame('floor_preparation', $catalog->issue('rough.floor', 'residential')['intent_action'] ?? null);
    }

    #[DataProvider('verifiedNormativeSpecializations')]
    public function test_issues_verified_search_and_rate_for_preliminary_material_scenario(
        string $workItemKey,
        string $expectedSearch,
        string $expectedCode,
    ): void {
        $catalog = new ResidentialMaterialScenarioCatalog;

        $issued = $catalog->issue($workItemKey, 'residential');

        self::assertIsArray($issued);
        self::assertSame($expectedSearch, $issued['normative_search_text']);
        self::assertSame($expectedCode, $issued['normative_rate_code']);
        self::assertSame($issued, $catalog->resolve($issued, $workItemKey, 'residential'));
    }

    public static function verifiedNormativeSpecializations(): iterable
    {
        yield 'foundation concrete preparation' => [
            'foundation.prep',
            'устройство бетонной подготовки фундаментов общего назначения',
            '06-01-001-01',
        ];
        yield 'wooden roof rafters' => [
            'roof.rafters',
            'установка деревянных стропил',
            '10-01-002-01',
        ];
        yield 'residential shower cabin with plastic tray' => [
            'sanitary.showers',
            'установка душевых кабин с пластиковым поддоном',
            '17-01-001-21',
        ];
        yield 'residential toilet' => [
            'sanitary.toilets',
            'установка унитазов с бачком непосредственно присоединенным',
            '17-01-003-01',
        ];
        yield 'residential washbasin' => [
            'sanitary.washbasins',
            'установка умывальников одиночных с подводкой холодной и горячей воды',
            '17-01-001-14',
        ];
        yield 'cement floor screed' => [
            'rough.floor',
            'устройство стяжек цементных толщиной 20 мм',
            '11-01-011-01',
        ];
        yield 'foundation coating waterproofing' => [
            'foundation.waterproofing',
            'боковая обмазочная битумная гидроизоляция',
            '08-01-003-07',
        ];
        yield 'external aerated concrete walls' => [
            'walls.external_volume',
            'кладка стен из газобетонных блоков на клее',
            '08-03-004-01',
        ];
        yield 'internal aerated concrete partitions' => [
            'walls.internal',
            'кладка перегородок из газобетонных блоков на клее',
            '08-04-003-01',
        ];
        yield 'mineral wool roof insulation' => [
            'roof.insulation',
            'утепление покрытий плитами из минеральной ваты насухо',
            '12-01-013-07',
        ];
        yield 'single layer pitched roof vapor barrier' => [
            'roof.vapor_barrier',
            'устройство пароизоляции кровли прокладочной в один слой',
            '12-01-015-03',
        ];
        yield 'timber battens with gaps' => [
            'roof.battens',
            'устройство деревянной обрешетки с прозорами из брусков',
            '12-01-034-02',
        ];
        yield 'metal tile roof covering' => [
            'roof.covering',
            'устройство простой кровли из металлочерепицы по готовым прогонам',
            '12-01-023-01',
        ];
        yield 'laminate floor' => [
            'finish.floor',
            'устройство покрытий из досок ламинированных замковым способом',
            '11-01-034-04',
        ];
        yield 'pvc baseboard' => [
            'finish.baseboard',
            'устройство плинтусов поливинилхлоридных на винтах самонарезающих',
            '11-01-040-03',
        ];
        yield 'residential stair' => [
            'stairs.flights',
            'устройство внутриквартирных лестниц без подшивки',
            '10-01-052-02',
        ];
        yield 'pvc windows' => [
            'openings.windows',
            'установка оконных блоков из ПВХ профилей двухстворчатых площадью до 2 м2',
            '10-01-034-05',
        ];
        yield 'horizontal grounding conductor' => [
            'electrical.grounding',
            'заземлитель горизонтальный из круглой стали диаметром 12 мм',
            '08-02-472-01',
        ];
        yield 'residential feeder cable' => [
            'electrical.main_cable',
            'прокладка кабеля с креплением скобами и установкой ответвительных коробок',
            '08-02-401-01',
        ];
        yield 'residential power wiring' => [
            'electrical.power_lines',
            'прокладка проводов силовой сети в готовых каналах сечением до 6 мм2',
            '08-02-404-01',
        ];
        yield 'residential lighting wiring' => [
            'lighting.lines',
            'прокладка проводов групповых осветительных сетей под штукатурку или в бороздах',
            '08-02-403-03',
        ];
        yield 'residential recessed lighting panel' => [
            'electrical.panel',
            'щиток осветительный устанавливаемый в нише распорными дюбелями массой до 6 кг',
            '08-03-599-01',
        ];
        yield 'residential recessed socket' => [
            'electrical.outlets',
            'розетка штепсельная утопленного типа при скрытой проводке',
            '08-03-591-09',
        ];
        yield 'residential recessed switch' => [
            'electrical.switches',
            'выключатель одноклавишный утопленного типа при скрытой проводке',
            '08-03-591-02',
        ];
        yield 'residential ceiling luminaire' => [
            'lighting.fixtures',
            'светильник потолочный с креплением винтами для помещений с нормальными условиями среды одноламповый',
            '08-03-593-06',
        ];
        yield 'residential aluminium or bimetallic radiator sections' => [
            'heating.radiators',
            'установка алюминиевых и биметаллических секционных радиаторов',
            '18-03-006-02',
        ];
        yield 'wet zone waterproofing' => [
            'sanitary.waterproofing',
            'устройство обмазочной гидроизоляции битумной мастикой в один слой толщиной 2 мм',
            '11-01-004-05',
        ];
        yield 'wet zone wall tile' => [
            'sanitary.tile',
            'гладкая облицовка стен керамическими плитками на клее по кирпичу и бетону',
            '15-01-019-05',
        ];
    }

    public function test_uses_single_sheet_layer_rate_for_diffusion_membrane_with_explicit_material_substitution(): void
    {
        $issued = (new ResidentialMaterialScenarioCatalog)->issue('roof.membrane', 'residential');

        self::assertIsArray($issued);
        self::assertSame('waterproofing', $issued['intent_action']);
        self::assertSame(
            'устройство пароизоляции кровли прокладочной в один слой',
            $issued['normative_search_text'],
        );
        self::assertSame('12-01-015-03', $issued['normative_rate_code']);
        self::assertSame('Укладка подкровельной диффузионной мембраны', $issued['work_item_name']);
    }

    public function test_combines_internal_sewer_risers_and_revisions_under_verified_pipeline_rate(): void
    {
        $issued = (new ResidentialMaterialScenarioCatalog)->issue('sewerage.pipe', 'residential');

        self::assertIsArray($issued);
        self::assertSame('16-04-004-01', $issued['normative_rate_code']);
        self::assertSame(
            'Прокладка внутренней канализации со стояками и ревизиями',
            $issued['work_item_name'],
        );
        self::assertSame('pipeline_installation', $issued['intent_action']);
    }
}
