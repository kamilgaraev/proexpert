<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantDetailContinuationIntent
{
    public static function matches(string $query, bool $includeFinancial = true): bool
    {
        $normalized = self::normalize(mb_strtolower($query));
        if ($normalized === '' || mb_strlen($normalized) > 160) {
            return false;
        }

        $normalized = self::normalize(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized);
        if ($normalized === '') {
            return false;
        }

        if (in_array($normalized, ['надо по деньгам', 'по деньгам', 'какие это позиции по смете'], true)) {
            return $includeFinancial;
        }

        if (in_array($normalized, [
            'точнее',
            'давай конкретнее а не около',
            'какие позиции',
            'что именно',
        ], true)) {
            return true;
        }

        return preg_match(
            '/^(?:а\s+)?(?:(?:давай|можно|расскажи|покажи(?:те)?)\s+)?(?:(?:еще|ещё|чуть|более)\s+)?(?:подробнее|подробней|детальнее|поясни|распиши|расшифруй|разверни|детали)(?:\s+(?:это|его|её|ее|их|тут|там|здесь|об\s+этом|в\s+этом|на\s+этом|по\s+(?:этому|нему|ней|ним|этому\s+вопросу|этим\s+данным|этой\s+части|этому\s+пункту)))?$/u',
            $normalized
        ) === 1;
    }

    private static function normalize(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim($value));

        return is_string($normalized) ? $normalized : trim($value);
    }
}
