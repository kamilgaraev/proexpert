<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantBimAnswerIntent
{
    public static function materialsAndQuantities(string $query): bool
    {
        $quantityQuery = preg_replace('/(?<!\pL)количеств[а-яё]*\s+(?:(?:вс[её][а-яё]*|разн[а-яё]*|различн[а-яё]*|уникальн[а-яё]*|вид[а-яё]*|тип[а-яё]*)\s+)*материал[а-яё]*(?!\pL)/iu', '', $query) ?? $query;
        $negatedPrefix = '(?<!\pL)(?:без|не)\s+(?:[\pL\pN-]+\s+){0,4}';
        $propertyQuery = preg_replace('/'.$negatedPrefix.'свойств[а-яё]*(?!\pL)|(?<!\pL)свойств[а-яё]*\s+(?:не\s+нуж[а-яё]*|исключи[а-яё]*|убери[а-яё]*)/iu', '', $query) ?? $query;
        $linkFacet = '(?:сметн[а-яё]*\s+)?связ[а-яё]*(?:\s+со?\s+смет[а-яё]*(?:\s+позици[а-яё]*)?)?';
        $additionalQuery = preg_replace('/'.$negatedPrefix.$linkFacet.'(?!\pL)|(?<!\pL)'.$linkFacet.'\s+(?:не\s+нуж[а-яё]*|исключи[а-яё]*|убери[а-яё]*)/iu', '', $propertyQuery) ?? $propertyQuery;

        return preg_match('/(?<!\pL)материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:объ[её]м[а-яё]*|единиц[а-яё]*\s+измерени[а-яё]*|количеств[а-яё]*)(?!\pL)/iu', $quantityQuery) === 1
            && preg_match('/'.$negatedPrefix.'(?:материал|объ[её]м|количеств|единиц)|(?<!\pL)(?:материал|объ[её]м|количеств|единиц)[а-яё]*\s+(?:не\s+нуж[а-яё]*|исключи[а-яё]*|убери[а-яё]*)/iu', $query) !== 1
            && preg_match('/(?<!\pL)(?:свойств|связ|сметн)[а-яё]*(?!\pL)/iu', $additionalQuery) !== 1;
    }

    public static function materialList(string $query): bool
    {
        return preg_match('/(?<!\pL)(?:каки[ея]|список|перечень|названи[ея]|покажи[а-яё]*)\s+(?:все[х]?\s+)?материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:модел[а-яё]*|ifc|bim|бим)(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:и|также|кроме|сколько|стоим[а-яё]*|цен[а-яё]*)(?!\pL)|;/iu', $query) !== 1;
    }

}
