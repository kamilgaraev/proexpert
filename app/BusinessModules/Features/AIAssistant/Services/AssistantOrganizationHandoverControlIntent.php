<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final class AssistantOrganizationHandoverControlIntent
{
    public static function hasIntent(string $query): bool
    {
        return AssistantRequestProjectScope::organizationWide($query)
            && preg_match('/при[её]мк[а-яё]*|передач[а-яё]*\s+зон/iu', $query) === 1;
    }

    public static function matches(string $query): bool
    {
        if (! self::hasIntent($query)
            || preg_match('/не\s+принят[а-яё]*\s+и\s+не\s+передан[а-яё]*|непринят[а-яё]*\s+и\s+непередан[а-яё]*/iu', $query) !== 1) {
            return false;
        }
        $query = preg_replace('/не\s+ограничивай(?:ся|тесь)\s+(?:выбранным|текущим)\s+(?:проектом|объектом)/iu', '', $query) ?? $query;
        $query = preg_replace('/закрыт[а-яё]*\s+замечан[а-яё]*\s+не\s+(?:считай(?:те)?|учитывай(?:те)?)/iu', '', $query) ?? $query;
        $terms = 'какие|покажи(?:те)?|перечисли(?:те)?|выведи(?:те)?|дай(?:те)?|пожалуйста|при[её]мк[а-яё]*|зон[а-яё]*|по|в|во|всей|нашей|организац[а-яё]*|ещ[её]|пока|не|непринят[а-яё]*|непередан[а-яё]*|принят[а-яё]*|передан[а-яё]*|и|каждой|каждую|укажи(?:те)?|проект|название|наименование|текущий|статус|планов(?:ую|ая)|дат[ау]|число|количество|открытых|замечаний';
        $remaining = preg_replace('/(?<![\pL\pN_])(?:'.$terms.')(?![\pL\pN_])/iu', '', $query) ?? $query;

        return preg_match('/^[\s.,;:!?()—–-]*$/uD', $remaining) === 1;
    }
}
