<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Normatives;

use App\BusinessModules\Addons\EstimateGeneration\Normatives\Services\ResidentialMaterialScenarioCatalog;
use PHPUnit\Framework\TestCase;

final class ResidentialMaterialScenarioCatalogTest extends TestCase
{
    public function test_issues_and_validates_only_catalog_owned_residential_scenarios(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;

        $issued = $catalog->issue('finish.floor', 'residential');

        self::assertIsArray($issued);
        self::assertSame('residential_preliminary_common:v1', $issued['scenario_id']);
        self::assertSame('finish.floor', $issued['work_item_key']);
        self::assertSame(['ламинат'], $issued['material_markers']);
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
    }

    public function test_validation_is_stable_after_json_object_key_reordering(): void
    {
        $catalog = new ResidentialMaterialScenarioCatalog;
        $issued = $catalog->issue('finish.floor', 'residential');

        self::assertIsArray($issued);
        $reordered = json_decode(json_encode([
            'signature' => $issued['signature'],
            'assumption_code' => $issued['assumption_code'],
            'material_markers' => $issued['material_markers'],
            'object_type' => $issued['object_type'],
            'work_item_key' => $issued['work_item_key'],
            'scenario_id' => $issued['scenario_id'],
            'version' => $issued['version'],
        ], JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($issued, $catalog->resolve($reordered, 'finish.floor', 'residential'));
    }
}
