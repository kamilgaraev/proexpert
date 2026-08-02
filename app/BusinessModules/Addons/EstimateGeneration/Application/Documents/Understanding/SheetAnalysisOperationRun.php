<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Application\Documents\Understanding;

use App\BusinessModules\Addons\EstimateGeneration\Vision\DTO\VisionAnalysisData;

final readonly class SheetAnalysisOperationRun
{
    /** @param array<string, mixed> $routing */
    private function __construct(public ?VisionAnalysisData $analysis, public array $routing, public string $outcome) {}
    /** @param array<string, mixed> $routing */ public static function performed(VisionAnalysisData $analysis, array $routing): self { return new self($analysis, $routing, 'succeeded'); }
    /** @param array<string, mixed> $routing */ public static function replayed(VisionAnalysisData $analysis, array $routing): self { return new self($analysis, $routing, 'replayed'); }
    /** @param array<string, mixed> $routing */ public static function needsReview(array $routing): self { return new self(null, $routing, 'needs_review'); }
}
