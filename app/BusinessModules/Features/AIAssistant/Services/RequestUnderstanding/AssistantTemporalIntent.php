<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\RequestUnderstanding;

final class AssistantTemporalIntent
{
    private const CALENDAR = '(?<!\pL)(?:январ[а-яё]*|феврал[а-яё]*|март[а-яё]*|апрел[а-яё]*|ма[йеяю]|июн[а-яё]*|июл[а-яё]*|август[а-яё]*|сентябр[а-яё]*|октябр[а-яё]*|ноябр[а-яё]*|декабр[а-яё]*)(?!\pL)|(?<!\d)\d{4}(?!\d)|(?<!\d)\d{1,2}[.\/-]\d{1,2}(?:[.\/-]\d{2,4})?(?!\d)';

    public static function hasExplicitWindow(string $query): bool
    {
        return preg_match('/'.self::CALENDAR.'|сегодня|завтра|вчера|(?<!\pL)(?:день|дня|дней|сутки|суток|недел[а-яё]*|месяц[а-яё]*|год|года|году|лет|квартал[а-яё]*|период[а-яё]*)(?!\pL)/iu', $query) === 1;
    }

    public static function hasNonCurrentScope(string $query): bool
    {
        return preg_match('/'.self::CALENDAR.'|вчера|завтра|раньше|ранее|прежн|истори|действовал|(?<!\pL)(?:был[аои]?|будут|будет)(?!\pL)|прошл[а-яё]*\s+(?:месяц|год|недел)|следующ[а-яё]*\s+(?:месяц|год|недел)|до\s+(?:изменен|обновлен)|на\s+дату|по\s+состоянию\s+на/iu', $query) === 1;
    }
}
