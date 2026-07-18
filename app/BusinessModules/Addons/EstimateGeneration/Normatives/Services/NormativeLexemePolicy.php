<?php

declare(strict_types=1);

namespace App\BusinessModules\Addons\EstimateGeneration\Normatives\Services;

final readonly class NormativeLexemePolicy
{
    private const GENERIC_TERMS = [
        'монтаж',
        'устройство',
        'отделка',
        'работа',
        'работы',
        'система',
        'системы',
    ];

    public static function isGeneric(string $term): bool
    {
        return in_array(mb_strtolower(trim($term)), self::GENERIC_TERMS, true);
    }
}
