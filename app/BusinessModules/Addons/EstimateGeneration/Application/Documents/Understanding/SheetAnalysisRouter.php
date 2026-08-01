<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;

final readonly class SheetAnalysisRouter
{
    public function __construct(private SheetRoleClassifier $classifier) {}

    public function route(VisionAnalysisData $analysis, ?string $nativeText = null): SheetAnalysisRoutingResult
    {
        $classification = $this->classifier->classify($analysis, $nativeText);
        $limits = is_array(config('estimate-generation.vision.sheet_routing'))
            ? config('estimate-generation.vision.sheet_routing')
            : [];

        return new SheetAnalysisRoutingResult(
            $classification,
            $this->bounded($limits['max_facts'] ?? 64, 1, 500),
            $this->bounded($limits['max_elements'] ?? 96, 1, 500),
            $this->bounded($limits['max_output_tokens'] ?? 2_048, 256, 16_384),
        );
    }

    private function bounded(mixed $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, (int) $value));
    }
}
