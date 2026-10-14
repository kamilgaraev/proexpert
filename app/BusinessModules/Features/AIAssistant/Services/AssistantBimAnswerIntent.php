<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantBimAnswerIntent
{
    public static function materialsAndQuantities(string $query): bool
    {
        $quantityQuery = preg_replace('/(?<!\pL)количеств[а-яё]*\s+(?:(?:вс[её][а-яё]*|разн[а-яё]*|различн[а-яё]*|уникальн[а-яё]*|вид[а-яё]*|тип[а-яё]*)\s+)*материал[а-яё]*(?!\pL)/iu', '', $query) ?? $query;
        $propertyQuery = preg_replace('/(?<!\pL)(?:без|не)\s+свойств[а-яё]*(?!\pL)/iu', '', $query) ?? $query;

        return preg_match('/(?<!\pL)материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:объ[её]м[а-яё]*|единиц[а-яё]*\s+измерени[а-яё]*|количеств[а-яё]*)(?!\pL)/iu', $quantityQuery) === 1
            && preg_match('/(?<!\pL)(?:не|без)\s+(?:(?:показывай|показывать|выводи|выводить|считай|считать|включай|включать|нужно|надо)\s+){0,2}(?:материал|объ[её]м|количеств|единиц)|(?<!\pL)(?:материал|объ[её]м|количеств|единиц)[а-яё]*\s+(?:не\s+нуж[а-яё]*|исключи[а-яё]*|убери[а-яё]*)|связ[а-яё]*|сметн[а-яё]*/iu', $query) !== 1
            && preg_match('/(?<!\pL)свойств[а-яё]*(?!\pL)/iu', $propertyQuery) !== 1;
    }

    public static function materialList(string $query): bool
    {
        return preg_match('/(?<!\pL)(?:каки[ея]|список|перечень|названи[ея]|покажи[а-яё]*)\s+(?:все[х]?\s+)?материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:модел[а-яё]*|ifc|bim|бим)(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:и|также|кроме|сколько|стоим[а-яё]*|цен[а-яё]*)(?!\pL)|;/iu', $query) !== 1;
    }

}
