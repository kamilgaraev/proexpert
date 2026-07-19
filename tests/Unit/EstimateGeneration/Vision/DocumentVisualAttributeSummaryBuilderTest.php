<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DocumentVisualAttributeSummaryBuilder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentVisualAttributeSummaryBuilderTest extends TestCase
{
    #[Test]
    public function consistent_elevation_roofs_are_normalized_to_pitched(): void
    {
        $result = (new DocumentVisualAttributeSummaryBuilder)->summarize([
            $this->payload('elevation', 'gable', 0.93),
            $this->payload('elevation', 'pitched', 0.88),
            $this->payload('floor_plan', 'unknown', 1.0),
        ]);

        self::assertSame(['roof_type' => 'pitched'], $result);
    }

    #[Test]
    public function conflicting_visible_roof_types_are_not_promoted(): void
    {
        $result = (new DocumentVisualAttributeSummaryBuilder)->summarize([
            $this->payload('elevation', 'pitched', 0.91),
            $this->payload('elevation', 'flat', 0.92),
        ]);

        self::assertSame([], $result);
    }

    private function payload(string $sheetType, string $value, float $confidence): array
    {
        return ['vision_analysis' => [
            'sheet_type' => $sheetType,
            'visual_attributes' => [
                'roof_type' => ['value' => $value, 'confidence' => $confidence, 'evidence_ref' => 'page-1'],
            ],
        ]];
    }
}
