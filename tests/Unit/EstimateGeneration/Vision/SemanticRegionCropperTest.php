<?php

declare(strict_types=1);

namespace Tests\Unit\EstimateGeneration\Vision;

use App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionCropper;
use App\BusinessModules\Addons\EstimateGeneration\Vision\Regions\SemanticRegionIngestor;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SemanticRegionCropperTest extends TestCase
{
    #[Test]
    public function renders_only_bounded_semantic_crops_from_the_full_page(): void
    {
        $page = imagecreatetruecolor(1200, 800);
        imagefill($page, 0, 0, imagecolorallocate($page, 255, 255, 255));
        imagestring($page, 5, 800, 600, 'A-17 3200', imagecolorallocate($page, 0, 0, 0));
        ob_start();
        imagepng($page);
        $bytes = ob_get_clean();
        imagedestroy($page);
        self::assertIsString($bytes);
        $regions = (new SemanticRegionIngestor(2, 1_000_000))->ingest([
            ['label' => 'Размерная цепочка', 'purpose' => 'microtext', 'box' => [0.6, 0.65, 0.98, 0.95]],
            ['label' => 'Штамп', 'purpose' => 'title_block', 'box' => [0.7, 0.7, 1.0, 1.0]],
        ], 1200, 800);

        $crops = (new SemanticRegionCropper(maxRegions: 1, maxAggregateBytes: 2_000_000, maxLongEdge: 1000))->crop($bytes, $regions);

        self::assertCount(1, $crops);
        self::assertSame('image/png', $crops[0]['content_type']);
        self::assertSame('sha256:'.hash('sha256', $crops[0]['image_content']), $crops[0]['sha256']);
        self::assertGreaterThan(1200 * 0.38, getimagesizefromstring($crops[0]['image_content'])[0]);
    }
}
