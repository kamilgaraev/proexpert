<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Services\Learning;

final class EstimateGenerationQuantityLearningKey
{
    public static function fromQuantityKey(string $quantityKey): string
    {
        $normalized = self::normalizeQuantityKey($quantityKey);

        if ($normalized === '') {
            $normalized = substr(hash('sha256', $quantityKey), 0, 24);
        }

        $code = 'quantity:' . $normalized;

        if (strlen($code) <= 100) {
            return $code;
        }

        return 'quantity:' . substr(hash('sha256', $quantityKey), 0, 32);
    }

    public static function normalizeQuantityKey(string $quantityKey): string
    {
        $normalized = mb_strtolower(trim($quantityKey));
        $normalized = preg_replace('/[^a-z0-9_.:-]+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }
}
