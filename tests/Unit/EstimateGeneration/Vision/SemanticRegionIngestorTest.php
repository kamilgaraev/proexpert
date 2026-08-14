<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionIngestor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SemanticRegionIngestorTest extends TestCase
{
    #[Test]
    public function unicode_purpose_is_accepted_and_oversized_source_is_quarantined_without_throwing(): void
    {
        $ingestor = new SemanticRegionIngestor(maxSourcePixels: 1_000_000);
        $payload = [[
            'label' => 'Размерная цепочка',
            'purpose' => 'Чтение мелких размеров',
            'box' => [0.1, 0.1, 0.5, 0.5],
        ]];

        $accepted = $ingestor->ingest($payload, 1_000, 1_000);
        $oversized = $ingestor->ingest($payload, 2_000, 1_000);

        self::assertSame('Чтение мелких размеров', $accepted->regions[0]->purpose);
        self::assertSame([], $oversized->regions);
        self::assertSame('source_pixel_budget_exceeded', $oversized->quarantined[0]['reason']);
    }

    #[Test]
    public function invalid_region_is_quarantined_without_losing_valid_regions(): void
    {
        $result = (new SemanticRegionIngestor(maxRegions: 3, maxAggregatePixels: 3_000_000))->ingest([
            ['label' => 'План этажа', 'purpose' => 'drawing', 'box' => [0.05, 0.05, 0.65, 0.75]],
            ['label' => "Опасная\u{0007}метка", 'purpose' => 'microtext', 'box' => [0.1, 0.1, 0.2, 0.2]],
            ['label' => 'Штамп', 'purpose' => 'title_block', 'box' => [0.72, 0.78, 0.98, 0.98]],
        ], sourceWidth: 2400, sourceHeight: 1600);

        self::assertCount(2, $result->regions);
        self::assertCount(1, $result->quarantined);
        self::assertSame('invalid_region_coordinates', $result->quarantined[0]['reason']);
        self::assertMatchesRegularExpression('/^region:[a-f0-9]{24}$/', $result->regions[0]->id);
    }

    #[Test]
    public function region_count_and_aggregate_pixels_are_bounded(): void
    {
        $result = (new SemanticRegionIngestor(maxRegions: 2, maxAggregatePixels: 1_000_000))->ingest([
            ['label' => 'A', 'purpose' => 'drawing', 'box' => [0.0, 0.0, 0.5, 0.5]],
            ['label' => 'B', 'purpose' => 'drawing', 'box' => [0.5, 0.0, 1.0, 0.5]],
            ['label' => 'C', 'purpose' => 'drawing', 'box' => [0.0, 0.5, 0.5, 1.0]],
        ], sourceWidth: 2000, sourceHeight: 2000);

        self::assertCount(1, $result->regions);
        self::assertSame(1_000_000, $result->aggregatePixels);
        self::assertSame(['region_pixel_budget_exceeded', 'region_count_exceeded'], array_column($result->quarantined, 'reason'));
    }
}
