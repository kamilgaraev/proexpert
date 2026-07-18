<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final class NormativeResourceUnitNormalizer
{
    public static function normalize(?string $unit, string $resourceType): ?string
    {
        $unit = trim((string) $unit);
        if ($unit !== '') {
            return $unit;
        }

        return match ($resourceType) {
            'labor', 'machine_labor' => 'чел.-ч',
            'machine', 'machinery' => 'маш.-ч',
            default => null,
        };
    }
}
