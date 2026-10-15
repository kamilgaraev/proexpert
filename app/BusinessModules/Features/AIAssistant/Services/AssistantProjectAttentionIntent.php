<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantProjectAttentionIntent
{
    public static function matches(string $query): bool
    {
        if (preg_match('/создай|назначь|закрой|удали|одобри|отправь|(?<!\pL)поручи(?:те)?(?!\pL)|деньг|денег|денежн|сч[её]т|плат[её]ж|оплат|оплаче|потрач|сумм|выручк|марж|рентабел|долг|бюджет|финанс|валют|затрат|расход|прибыл|стоим|договор|документ|ifc|bim|бим/iu', $query)) { return false; }
        return self::focus($query) !== null && (preg_match('/требу[а-яё]*\s+(?:моего\s+)?внимани|внимани[а-яё]*\s+в\s+первую\s+очередь|приоритет|главн[а-яё]*\s+проблем|план[её]рк|риски\s+(?:проекта|объекта)/iu', $query) === 1
            || self::focus($query) === 'quality' && preg_match('/(?:что\s+(?:мне\s+)?|кому\s+)поручить/iu', $query) === 1
            || self::focus($query) === 'procurement' && preg_match('/что\s+со\s+снабжением|требу[а-яё]*\s+решени|помешать\s+ближайшим\s+работ/iu', $query) === 1);
    }

    public static function focus(string $query): ?string
    {
        $found = [];
        foreach (['schedule' => '/график|просрочен[а-яё]*\s+(?:задач|срок)|(?:проблем[а-яё]*|риски)\s+(?:сроков|задач)|по\s+срокам/iu', 'quality' => '/качеств|замечан|дефект/iu', 'procurement' => '/снабжен|закуп|поставк/iu'] as $focus => $pattern) {
            if (preg_match($pattern, $query)) { $found[] = $focus; }
        }
        return count($found) > 1 ? null : ($found[0] ?? 'all');
    }
}
