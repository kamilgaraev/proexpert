<?php

declare(strict_types=1);

namespace Tests\Unit\AIAssistant\Rag;

use App\BusinessModules\Features\AIAssistant\DTOs\Rag\RagChunkData;
use App\BusinessModules\Features\AIAssistant\Services\Rag\Sources\EstimateRagSource;
use App\Models\Contract;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\EstimateSection;
use App\Models\MeasurementUnit;
use App\Models\Project;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class EstimateRagSourceOrderingTest extends TestCase
{
    public function test_equal_amount_items_are_ordered_by_id_for_stable_checksum(): void
    {
        $source = new EstimateRagSource();
        $chunkMethod = new ReflectionMethod($source, 'chunk');
        $chunkMethod->setAccessible(true);

        $chunk = $chunkMethod->invoke($source, $this->estimateWithItemsInUnstableOrder());

        $this->assertInstanceOf(RagChunkData::class, $chunk);
        $this->assertLessThan(
            strpos($chunk->content, 'Equal amount later item'),
            strpos($chunk->content, 'Equal amount earlier item')
        );
    }

    private function estimateWithItemsInUnstableOrder(): Estimate
    {
        $estimate = new Estimate([
            'id' => 101,
            'organization_id' => 10,
            'project_id' => 20,
            'contract_id' => 30,
            'number' => 'EST-101',
            'name' => 'Stable estimate',
            'status' => 'approved',
            'type' => 'local',
            'total_amount' => 200000,
            'total_amount_with_vat' => 240000,
        ]);

        $estimate->setRelation('project', new Project(['id' => 20, 'name' => 'Stable project']));
        $estimate->setRelation('contract', new Contract(['id' => 30, 'number' => 'CTR-30']));
        $estimate->setRelation('sections', new Collection([
            new EstimateSection([
                'id' => 501,
                'estimate_id' => 101,
                'section_number' => '1',
                'name' => 'Main section',
                'section_total_amount' => 200000,
            ]),
        ]));

        $unit = new MeasurementUnit(['id' => 40, 'name' => 'm3']);

        $laterItem = new EstimateItem([
            'estimate_id' => 101,
            'estimate_section_id' => 501,
            'position_number' => '0-A',
            'name' => 'Equal amount later item',
            'normative_rate_code' => 'RATE-LATER',
            'quantity' => 20,
            'quantity_total' => 20,
            'current_total_amount' => 100000,
        ]);
        $laterItem->setAttribute('id', 702);
        $laterItem->setRelation('measurementUnit', $unit);

        $earlierItem = new EstimateItem([
            'estimate_id' => 101,
            'estimate_section_id' => 501,
            'position_number' => '1-A',
            'name' => 'Equal amount earlier item',
            'normative_rate_code' => 'RATE-EARLIER',
            'quantity' => 20,
            'quantity_total' => 20,
            'current_total_amount' => 100000,
        ]);
        $earlierItem->setAttribute('id', 701);
        $earlierItem->setRelation('measurementUnit', $unit);

        $estimate->setRelation('items', new Collection([$laterItem, $earlierItem]));

        return $estimate;
    }
}
