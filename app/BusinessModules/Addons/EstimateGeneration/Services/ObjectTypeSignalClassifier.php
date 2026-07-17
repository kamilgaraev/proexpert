<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services;

final class ObjectTypeSignalClassifier
{
    public static function isResidential(string $text): bool
    {
        return preg_match('/(?:^|[^\p{L}\p{N}])(?:ижс|жил\p{L}*|дом|house|residential)(?=$|[^\p{L}\p{N}])/u', mb_strtolower($text)) === 1;
    }

    public static function canonical(string $text): string
    {
        $normalized = mb_strtolower(trim($text));
        if (self::isResidential($normalized)) {
            return 'residential';
        }
        if ((str_contains($normalized, 'office') || str_contains($normalized, 'офис'))
            && (str_contains($normalized, 'warehouse') || str_contains($normalized, 'склад'))) {
            return 'mixed_warehouse_office';
        }
        if (preg_match('/(?:^|[^\p{L}\p{N}])(?:офис\p{L}*|office)(?=$|[^\p{L}\p{N}])/u', $normalized) === 1) {
            return 'office';
        }
        if (preg_match('/(?:^|[^\p{L}\p{N}])(?:склад\p{L}*|warehouse)(?=$|[^\p{L}\p{N}])/u', $normalized) === 1) {
            return 'warehouse';
        }

        return $normalized;
    }

    public static function compatible(string $left, string $right): bool
    {
        $left = self::canonical($left);
        $right = self::canonical($right);

        return $left === $right
            || ($left === 'mixed_warehouse_office' && in_array($right, ['office', 'warehouse'], true))
            || ($right === 'mixed_warehouse_office' && in_array($left, ['office', 'warehouse'], true));
    }
}
