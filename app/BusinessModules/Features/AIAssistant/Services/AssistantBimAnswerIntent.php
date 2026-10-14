<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantBimAnswerIntent
{
    public static function materialsAndQuantities(string $query): bool
    {
        return preg_match('/(?<!\pL)материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:объ[её]м[а-яё]*|единиц[а-яё]*)(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:не|без)\s+(?:материал|объ[её]м|количеств|единиц)|связ[а-яё]*|сметн[а-яё]*/iu', $query) !== 1;
    }

    public static function materialList(string $query): bool
    {
        return preg_match('/(?<!\pL)(?:каки[ея]|список|перечень|названи[ея]|покажи[а-яё]*)\s+(?:все[х]?\s+)?материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:модел[а-яё]*|ifc|bim|бим)(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:и|также|кроме|сколько|стоим[а-яё]*|цен[а-яё]*)(?!\pL)|;/iu', $query) !== 1;
    }

}
