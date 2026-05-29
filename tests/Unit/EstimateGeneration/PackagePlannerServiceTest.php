<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\DTOs\ObjectProfileData;
use App\BusinessModules\Addons\EstimateGeneration\Services\PackagePlannerService;
use Tests\TestCase;

class PackagePlannerServiceTest extends TestCase
{
    public function test_house_plan_contains_required_local_estimates_and_has_product_density(): void
    {
        $profile = new ObjectProfileData(
            objectType: 'house',
            area: 150.0,
            floors: 2,
            rooms: 8,
            regionCode: 'RU-MOS',
            regionalPriceVersionId: 1,
            quarterKey: '2026-q1',
            dimensions: ['length' => 10, 'width' => 15],
            finishLevels: ['rough', 'finish'],
            engineeringSystems: ['electrical', 'plumbing', 'heating', 'ventilation'],
            assumptions: [],
            missingInputs: [],
            confidence: 0.86,
        );

        $plan = app(PackagePlannerService::class)->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertContains('foundation', $keys);
        $this->assertContains('walls', $keys);
        $this->assertContains('roof', $keys);
        $this->assertContains('electrical', $keys);
        $this->assertContains('heating', $keys);
        $this->assertGreaterThanOrEqual(250, $plan->targetItemsMinTotal());
        $this->assertGreaterThanOrEqual(15, count($plan->packages));
    }

    public function test_warehouse_plan_uses_industrial_packages_and_density(): void
    {
        $profile = new ObjectProfileData(
            objectType: 'warehouse',
            area: 2000.0,
            floors: 1,
            rooms: null,
            regionCode: 'RU-TA',
            regionalPriceVersionId: 1,
            quarterKey: '2026-q1',
            dimensions: [],
            finishLevels: [],
            engineeringSystems: ['electrical', 'ventilation', 'fire_safety'],
            assumptions: [],
            missingInputs: [],
            confidence: 0.82,
        );

        $plan = app(PackagePlannerService::class)->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertContains('metal_frame', $keys);
        $this->assertContains('industrial_floor', $keys);
        $this->assertContains('fire_safety', $keys);
        $this->assertGreaterThanOrEqual(600, $plan->targetItemsMinTotal());
    }

    public function test_profile_from_analysis_uses_ocr_document_context_for_warehouse_plan(): void
    {
        $analysis = [
            'object' => [
                'building_type' => 'custom',
                'description' => '',
                'area' => 1280,
                'zones' => [
                    ['scope_key' => 'warehouse_area', 'label' => 'Склад', 'area_m2' => 900.0],
                    ['scope_key' => 'office_area', 'label' => 'Офис', 'area_m2' => 280.0],
                ],
            ],
            'document_context' => [
                'context_text' => 'Складской корпус с офисной зоной, пожарной сигнализацией и освещением.',
            ],
        ];

        $profile = app(PackagePlannerService::class)->profileFromAnalysis($analysis);
        $plan = app(PackagePlannerService::class)->plan($profile);
        $keys = array_column($plan->packages, 'key');

        $this->assertSame('mixed_warehouse_office', $profile->objectType);
        $this->assertSame(1280.0, $profile->area);
        $this->assertContains('industrial_floor', $keys);
        $this->assertContains('office_partitions', $keys);
    }
}
