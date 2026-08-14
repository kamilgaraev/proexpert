<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Vision\Regions;

use InvalidArgumentException;

final readonly class SemanticRegionIngestor
{
    public function __construct(
        private int $maxRegions = 6,
        private int $maxAggregatePixels = 12_000_000,
        private int $maxSourcePixels = 20_000_000,
    ) {
        if ($maxRegions < 1 || $maxRegions > 16 || $maxAggregatePixels < 1 || $maxSourcePixels < 1) {
            throw new InvalidArgumentException('semantic_region_budget_invalid');
        }
    }

    /** @param list<mixed> $payload */
    public function ingest(array $payload, int $sourceWidth, int $sourceHeight): SemanticRegionSet
    {
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            throw new InvalidArgumentException('semantic_region_source_dimensions_invalid');
        }
        if ($sourceWidth > 50_000 || $sourceHeight > 50_000 || $sourceWidth * $sourceHeight > $this->maxSourcePixels) {
            return new SemanticRegionSet([], [[
                'index' => -1,
                'reason' => 'source_pixel_budget_exceeded',
            ]], 0);
        }
        $regions = [];
        $quarantined = [];
        $aggregatePixels = 0;
        foreach ($payload as $index => $raw) {
            if ($index >= $this->maxRegions) {
                $quarantined[] = ['index' => $index, 'reason' => 'region_count_exceeded'];

                continue;
            }
            $validated = $this->validate($raw);
            if ($validated === null) {
                $quarantined[] = ['index' => $index, 'reason' => 'invalid_region_coordinates'];

                continue;
            }
            [$label, $purpose, $box] = $validated;
            $width = max(1, (int) ceil(($box[2] - $box[0]) * $sourceWidth));
            $height = max(1, (int) ceil(($box[3] - $box[1]) * $sourceHeight));
            $pixels = $width * $height;
            if ($aggregatePixels + $pixels > $this->maxAggregatePixels) {
                $quarantined[] = ['index' => $index, 'reason' => 'region_pixel_budget_exceeded'];

                continue;
            }
            $id = 'region:'.substr(hash('sha256', json_encode([$index, $label, $purpose, $box], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), 0, 24);
            $regions[] = new SemanticRegion($id, $label, $purpose, $box, $pixels);
            $aggregatePixels += $pixels;
        }

        return new SemanticRegionSet($regions, $quarantined, $aggregatePixels);
    }

    /** @return array{string,string,array{0:float,1:float,2:float,3:float}}|null */
    private function validate(mixed $raw): ?array
    {
        if (! is_array($raw) || array_keys($raw) !== ['label', 'purpose', 'box']
            || ! is_string($raw['label']) || trim($raw['label']) === '' || mb_strlen($raw['label']) > 160
            || preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', $raw['label']) === 1
            || ! is_string($raw['purpose']) || trim($raw['purpose']) === '' || mb_strlen($raw['purpose']) > 160
            || preg_match('~[\x00-\x08\x0B\x0C\x0E-\x1F]~u', $raw['purpose']) === 1
            || ! is_array($raw['box']) || count($raw['box']) !== 4) {
            return null;
        }
        $box = [];
        foreach ($raw['box'] as $coordinate) {
            if ((! is_float($coordinate) && ! is_int($coordinate)) || ! is_finite((float) $coordinate)) {
                return null;
            }
            $box[] = (float) $coordinate;
        }
        if ($box[0] < 0.0 || $box[1] < 0.0 || $box[2] > 1.0 || $box[3] > 1.0
            || $box[2] - $box[0] < 0.02 || $box[3] - $box[1] < 0.02) {
            return null;
        }

        return [trim($raw['label']), trim($raw['purpose']), $box];
    }
}
