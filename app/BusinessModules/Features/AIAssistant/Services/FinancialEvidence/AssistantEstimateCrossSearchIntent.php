<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

final class AssistantEstimateCrossSearchIntent
{
    private const FILLER = [
        'есть', 'ли', 'у', 'нас', 'в', 'во', 'по', 'покажи', 'показать', 'найди', 'найти', 'ищи', 'посмотри',
        'проверь', 'проверить', 'имеется', 'имеются', 'встречается', 'встречаются', 'пожалуйста', 'позиция', 'позиции',
    ];

    public static function matches(string $query): bool
    {
        $pluralScope = preg_match('/(?<![\pL\pN])(?:сметах|сметам|сметами|все\s+сметы|всех\s+смет)(?![\pL\pN])/iu', $query) === 1;
        $explicitEstimateNumber = preg_match('/(?<![\pL\pN])смет[а-яё]*\s*(?:№|номер)\s*[\pL\pN._\/-]+/iu', $query) === 1;

        return $pluralScope && ! $explicitEstimateNumber && self::normalizeSearchTerms($query) !== null;
    }

    public static function unitPriceRead(string $query): ?array
    {
        if (preg_match('/(?<!\pL)(?:сколько\s+стоит|поч[её]м|цена(?:\s+за)?)(?:\s+один)?\s+(?:куб[а-яё]*|м[³3])\s+бетон[а-яё]*(?!\pL)/iu', $query, $match) !== 1) { return null; }
        $rest = str_replace($match[0], '', $query);
        preg_match_all('/[\pL\pN_]+/u', mb_strtolower($rest), $words);
        $allowed = [...self::FILLER, 'любой', 'любую', 'нашей', 'наших', 'наша', 'смета', 'смете', 'сметах', 'скажи', 'подскажи'];
        if (array_diff($words[0], $allowed) !== []) { return null; }

        return ['name' => 'search_estimate_positions', 'arguments' => ['query' => 'бетон', 'per_page' => 1, 'cursor' => null, 'price_subject' => 'concrete_m3']];
    }

    public static function isConcreteCubicPosition(array $position): bool
    {
        return preg_match('/^\s*(?:бетон(?!\pL)|бетоны(?!\pL)|смес[ьи]\s+бетонн[а-яё]*|БСТ(?!\pL))/iu', (string) ($position['name'] ?? '')) === 1
            && (! isset($position['item_type']) || $position['item_type'] === 'material')
            && in_array(mb_strtolower(trim((string) ($position['unit'] ?? ''))), ['м³', 'м3', 'm3', 'm³'], true);
    }

    public static function normalizeSearchTerms(string $query): ?string
    {
        $tokens = preg_split('/[^\pL\pN]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens)) {
            return null;
        }
        $terms = array_values(array_filter($tokens, static fn (string $token): bool => ! in_array($token, self::FILLER, true)
            && ! str_starts_with($token, 'смет')));

        return $terms === [] ? null : implode(' ', $terms);
    }

    public static function exactPositionCode(string $query): ?string
    {
        preg_match_all('/(?<![\\pL\\pN])[\\pL\\pN]+(?:[._\\/-][\\pL\\pN]+)+(?![\\pL\\pN])/u', $query, $matches);
        $codes = array_values(array_unique(array_filter($matches[0] ?? [], static fn (string $code): bool => preg_match('/\\d/u', $code) === 1)));

        return count($codes) === 1 ? mb_strtoupper($codes[0]) : null;
    }
}
