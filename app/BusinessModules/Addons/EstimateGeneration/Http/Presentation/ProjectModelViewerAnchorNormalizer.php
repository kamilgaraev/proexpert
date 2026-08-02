<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Http\Presentation;

final class ProjectModelViewerAnchorNormalizer
{
    /** @return list<array{0:float,1:float}>|null */
    public function polygon(mixed $raw, mixed $width, mixed $height): ?array
    {
        if (! is_array($raw) || ! array_is_list($raw) || count($raw) < 3 || count($raw) > 32
            || ! is_numeric($width) || ! is_numeric($height) || ! is_finite((float) $width) || ! is_finite((float) $height)
            || (float) $width <= 0 || (float) $height <= 0) {
            return null;
        }
        $width = (float) $width;
        $height = (float) $height;
        $normalized = [];
        foreach ($raw as $point) {
            if (! is_array($point) || ! array_is_list($point) || count($point) !== 2 || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                return null;
            }
            $x = (float) $point[0];
            $y = (float) $point[1];
            if (! is_finite($x) || ! is_finite($y) || $x < 0 || $y < 0 || $x > $width || $y > $height) {
                return null;
            }
            $normalized[] = [round($x / $width, 6), round($y / $height, 6)];
        }

        return $normalized;
    }
}
