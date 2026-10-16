<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Settings;

final class VisionOutputTokenPolicy
{
    public static function ceiling(): int
    {
        return max(256, min(16_384, (int) config('estimate-generation.vision.primary_max_output_tokens', 8_192)));
    }
}
