<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\ObjectQuantityModelService;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ObjectQuantityModelServiceTest extends TestCase
{
    #[DataProvider('objectScenarios')]
    public function test_quantity_model_builds_positive_key_quantities_for_different_objects(
        array $object,
        string $expectedPackageFamily,
        array $requiredQuantityKeys
    ): void {
        $analysis = [
            'object' => $object,
        ];

        $model = app(ObjectQuantityModelService::class)->build($analysis);
        $profile = app(PackagePlannerService::class)->profileFromAnalysis($analysis);
        $plan = app(PackagePlannerService::class)->plan($profile);
        $packageKeys = array_column($plan->packages, 'key');

        $this->assertGreaterThan(0, $model['area']);
        $this->assertGreaterThan(0, $model['perimeter']);
        $this->assertNotEmpty($model['assumptions']);

        foreach ($requiredQuantityKeys as $quantityKey) {
            $this->assertArrayHasKey($quantityKey, $model['quantities']);
            $this->assertGreaterThan(0, $model['quantities'][$quantityKey]['value']);
        }

        if ($expectedPackageFamily === 'warehouse') {
            $this->assertContains('industrial_floor', $packageKeys);
            $this->assertContains('metal_frame', $packageKeys);
            return;
        }

        $this->assertContains('foundation', $packageKeys);
        $this->assertContains('electrical', $packageKeys);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: array<int, string>}>
     */
    public static function objectScenarios(): array
    {
        return [
            'private_house' => [
                ['building_type' => 'Жилой дом', 'description' => 'Дом 214 кв м, 2 этажа, 7 комнат', 'area' => 214],
                'house',
                ['foundation.concrete', 'walls.external_volume', 'roof.area', 'electrical.points'],
            ],
            'warehouse' => [
                ['building_type' => 'Склад', 'description' => 'Теплый склад 2000 м2 с воротами', 'area' => 2000, 'floors' => 1],
                'warehouse',
                ['warehouse.floor', 'warehouse.frame_weight', 'warehouse.gates', 'warehouse.lighting'],
            ],
            'office' => [
                ['building_type' => 'Офисное здание', 'description' => 'Офис 600 м2, 3 этажа, кабинеты и переговорные', 'area' => 600, 'floors' => 3, 'rooms' => 18],
                'house',
                ['slabs.area', 'openings.windows', 'finish.paint', 'ventilation.points'],
            ],
            'shop' => [
                ['building_type' => 'Магазин', 'description' => 'Небольшой магазин 320 м2, один этаж, торговый зал', 'area' => 320, 'floors' => 1, 'rooms' => 6],
                'house',
                ['foundation.concrete', 'facade.area', 'electrical.points', 'rough.floor'],
            ],
            'garage_service' => [
                ['building_type' => 'Гараж и СТО', 'description' => 'СТО 450 м2, 1 этаж, 4 поста ремонта', 'area' => 450, 'floors' => 1, 'rooms' => 8],
                'house',
                ['earth.trench', 'slabs.area', 'electrical.cable', 'heating.radiators'],
            ],
            'small_production' => [
                ['building_type' => 'Производственное помещение', 'description' => 'Небольшое производство 1200 м2 с инженерией', 'area' => 1200, 'floors' => 1],
                'warehouse',
                ['warehouse.floor_concrete', 'warehouse.envelope', 'warehouse.fire', 'warehouse.roads'],
            ],
            'site_improvement' => [
                ['building_type' => 'Благоустройство', 'description' => 'Благоустройство территории 900 м2 с наружными сетями', 'area' => 900, 'floors' => 1],
                'house',
                ['siteworks.area', 'networks.external', 'site.fence', 'earth.plan'],
            ],
        ];
    }
}
