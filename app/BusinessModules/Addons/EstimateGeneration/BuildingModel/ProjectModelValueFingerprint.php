<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\BuildingModel;

use App\BusinessModules\Addons\EstimateGeneration\BuildingModel\DTO\BuildingModelSchema;

final class ProjectModelValueFingerprint
{
    /** @param array<string, mixed> $value */
    public static function for(array $value): string
    {
        return hash('sha256', BuildingModelSchema::canonicalJson(self::normalize($value)));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_int($value) || is_float($value)) {
            return ['number' => rtrim(rtrim(sprintf('%.17F', (float) $value), '0'), '.')];
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }
}
