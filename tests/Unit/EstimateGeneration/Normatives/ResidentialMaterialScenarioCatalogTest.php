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
        self::assertSame('residential_preliminary_common:v3', $issued['scenario_id']);
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
        self::assertSame('монтаж воздуховодов', $issued['normative_search_text']);
        self::assertContains('листовой оцинкованной стали', $issued['material_markers']);
        self::assertContains('диаметром до 200 мм', $issued['material_markers']);
        self::assertSame(
            $issued,
            $catalog->resolve($issued, 'ventilation.air_exchange', 'residential'),
        );
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
        yield 'steel radiators' => [
            'heating.radiators',
            'установка стальных радиаторов',
            '18-03-001-02',
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
}
