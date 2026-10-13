<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\DesignManagement\Support;

final class DesignBrand
{
    private const ALIASES = [
        'AR' => 'АР',
        'AS' => 'АС',
        'KR' => 'КР',
        'OV' => 'ОВ',
        'VK' => 'ВК',
        'EM' => 'ЭМ',
        'SS' => 'СС',
    ];

    public static function normalize(?string $value): ?string
    {
        $value = trim($value ?? '');
        if ($value === '') {
            return null;
        }

        $key = mb_strtoupper($value, 'UTF-8');
        if (preg_match('/^([A-Z]+)([0-9]*)$/D', $key, $parts) === 1 && isset(self::ALIASES[$parts[1]])) {
            return self::ALIASES[$parts[1]].$parts[2];
        }
        if (in_array($key, self::ALIASES, true)) {
            return $key;
        }

        return $value;
    }
}
