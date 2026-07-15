<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Pipeline;

final readonly class PipelineJson
{
    /** @param array<string, mixed> $value */
    public static function object(array $value): string
    {
        return json_encode((object) $value, JSON_THROW_ON_ERROR);
    }
}
