<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Regions;

use RuntimeException;

final readonly class SemanticRegionCropper
{
    public function __construct(
        private int $maxRegions = 8,
        private int $maxAggregateBytes = 12_000_000,
        private int $maxLongEdge = 2_400,
    ) {}

    /** @return list<array{id:string,label:string,purpose:string,box:array{0:float,1:float,2:float,3:float},content_type:string,image_content:string,sha256:string}> */
    public function crop(string $fullPage, SemanticRegionSet $set): array
    {
        $source = @imagecreatefromstring($fullPage);
        if (! $source instanceof \GdImage) {
            throw new RuntimeException('semantic_region_source_invalid');
        }
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $images = [];
        $aggregateBytes = 0;
        try {
            foreach (array_slice($set->regions, 0, $this->maxRegions) as $region) {
                $left = max(0, min($sourceWidth - 1, (int) floor($region->box[0] * $sourceWidth)));
                $top = max(0, min($sourceHeight - 1, (int) floor($region->box[1] * $sourceHeight)));
                $right = max($left + 1, min($sourceWidth, (int) ceil($region->box[2] * $sourceWidth)));
                $bottom = max($top + 1, min($sourceHeight, (int) ceil($region->box[3] * $sourceHeight)));
                $crop = imagecrop($source, ['x' => $left, 'y' => $top, 'width' => $right - $left, 'height' => $bottom - $top]);
                if (! $crop instanceof \GdImage) {
                    continue;
                }
                $longEdge = max(imagesx($crop), imagesy($crop));
                if ($longEdge < $this->maxLongEdge) {
                    $scale = min(3.0, $this->maxLongEdge / max(1, $longEdge));
                    $scaled = imagescale($crop, max(1, (int) round(imagesx($crop) * $scale)), max(1, (int) round(imagesy($crop) * $scale)), IMG_BICUBIC_FIXED);
                    if ($scaled instanceof \GdImage) {
                        imagedestroy($crop);
                        $crop = $scaled;
                    }
                }
                ob_start();
                $encoded = imagepng($crop, null, 6);
                $bytes = ob_get_clean();
                imagedestroy($crop);
                if (! $encoded || ! is_string($bytes) || $bytes === '' || $aggregateBytes + strlen($bytes) > $this->maxAggregateBytes) {
                    continue;
                }
                $aggregateBytes += strlen($bytes);
                $images[] = [
                    'id' => $region->id,
                    'label' => $region->label,
                    'purpose' => $region->purpose,
                    'box' => $region->box,
                    'content_type' => 'image/png',
                    'image_content' => $bytes,
                    'sha256' => 'sha256:'.hash('sha256', $bytes),
                ];
            }
        } finally {
            imagedestroy($source);
        }

        return $images;
    }
}
