<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration;

use App\BusinessModules\Addons\EstimateGeneration\Services\WorkItemGenerationService;
use Tests\TestCase;

class WorkItemGenerationDensityTest extends TestCase
{
    public function test_package_generation_respects_target_item_density(): void
    {
        $items = app(WorkItemGenerationService::class)->build([
            'key' => 'foundation',
            'title' => 'Фундамент',
            'scope_type' => 'foundation',
            'source_refs' => [],
            'target_items_min' => 30,
        ], [
            'object' => [
                'area' => 150,
            ],
        ]);

        $this->assertGreaterThanOrEqual(30, count($items));
        $this->assertSame('foundation-work-1', $items[0]['key']);
        $this->assertNotSame($items[0]['name'], $items[10]['name']);
    }
}
