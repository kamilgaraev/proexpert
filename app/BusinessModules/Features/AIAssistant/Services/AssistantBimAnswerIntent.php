<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services;

final readonly class AssistantBimAnswerIntent
{
    public static function materialList(string $query): bool
    {
        return preg_match('/(?<!\pL)(?:каки[ея]|список|перечень|названи[ея]|покажи[а-яё]*)\s+(?:все[х]?\s+)?материал[а-яё]*(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:модел[а-яё]*|ifc|bim|бим)(?!\pL)/iu', $query) === 1
            && preg_match('/(?<!\pL)(?:и|также|кроме|сколько|стоим[а-яё]*|цен[а-яё]*)(?!\pL)|;/iu', $query) !== 1;
    }

}
