<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Rag\MaterialSearch;

use InvalidArgumentException;

final readonly class MaterialSearchQuery
{
    public function __construct(public string $text, public int $limit = 10)
    {
        if (!mb_check_encoding($text, 'UTF-8') || strlen($text) > 1024
            || trim($text) === '' || $limit < 1 || $limit > 10) {
            throw new InvalidArgumentException('invalid_material_search_query');
        }
    }

    public static function normalizeUnit(string $unit): ?string
    {
        $unit = mb_strtolower(trim($unit), 'UTF-8');
        $unit = str_replace([' ', '.', '^'], '', $unit);

        return match ($unit) {
            'м³', 'м3', 'm³', 'm3', 'кубм', 'кубометр', 'кубическийметр' => 'm3',
            'м²', 'м2', 'm²', 'm2', 'квм', 'квадратныйметр' => 'm2',
            'кг', 'kg', 'килограмм' => 'kg',
            'т', 't', 'тонна' => 't',
            'шт', 'штука', 'item' => 'item',
            default => null,
        };
    }

    public function requestedUnit(): ?string
    {
        $text = mb_strtolower($this->text, 'UTF-8');

        foreach ([
            'm3' => '~(?<![\p{L}])(м\s*(?:³|\^?3)|m\s*(?:³|\^?3)|куб\.?\s*м\.?|кубометр\p{L}*|кубическ\p{L}*\s+метр\p{L}*)(?![\p{L}\d])~u',
            'm2' => '~(?<![\p{L}])(м\s*(?:²|\^?2)|m\s*(?:²|\^?2)|кв\.?\s*м\.?)(?![\p{L}\d])~u',
            'kg' => '~(?<![\p{L}])(кг|килограмм\p{L}*)(?![\p{L}])~u',
            't' => '~(?<![\p{L}])(тонн\p{L}*)(?![\p{L}])~u',
            'item' => '~(?<![\p{L}])(шт\.?|штук\p{L}*)(?![\p{L}])~u',
        ] as $unit => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return $unit;
            }
        }

        return null;
    }

    public static function tokens(string $text): array
    {
        $text = str_replace('ё', 'е', mb_strtolower($text, 'UTF-8'));
        $text = preg_replace('~(?<!\p{L})(за|по|на)(?=1\s*[мm](?:³|²|\^?[23]))~u', '$1 ', $text) ?? $text;
        $text = preg_replace('~(?<![\p{L}\p{N}])(?:1\s*)?(?:[мm]\s*(?:³|²|\^?[23])|куб\.?\s*м\.?)(?![\p{L}\p{N}])~u', ' ', $text) ?? $text;
        $parts = preg_split('~[^\p{L}\p{N}]+~u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $aliases = [
            'бетона' => 'бетон', 'бетону' => 'бетон', 'бетоном' => 'бетон',
            'бетонная' => 'бетон', 'бетонную' => 'бетон', 'бетонной' => 'бетон',
            'бетонные' => 'бетон', 'бетонного' => 'бетон',
            'смеси' => 'смесь',
            'товарного' => 'товарный', 'товарную' => 'товарный',
            'готовая' => 'готовый', 'готовую' => 'готовый',
            'перемычки' => 'перемычка', 'гидроизоляцию' => 'гидроизоляция',
        ];
        $stop = ['найди', 'найти', 'покажи', 'нужен', 'нужна', 'нужно', 'с', 'за', 'по', 'на', 'в', 'и',
            'цена', 'ценой', 'цену', 'стоимость', 'единица', 'м', 'м3', 'м2', 'm3', 'm2',
            'куб', 'кубометр', 'кубометра', 'кубометров', 'метр', 'метра', 'метров', 'кубический', 'кубических', '1'];
        $tokens = [];

        foreach ($parts ?: [] as $part) {
            $token = $aliases[$part] ?? $part;

            if (!in_array($token, $stop, true)) {
                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }
}
