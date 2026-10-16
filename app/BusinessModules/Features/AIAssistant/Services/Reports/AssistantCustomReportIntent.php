<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;

final class AssistantCustomReportIntent
{
    public static function matches(string $message, AssistantRequestUnderstanding $understanding): bool
    {
        return $understanding->primaryIntent === 'generate_report' && ! $understanding->blocksFileGeneration()
            && (in_array($understanding->outputFormat, ['xlsx', 'csv'], true)
                || preg_match('/произвольн|настраиваем|группир|разбивк|колонк|столбц|с\s+полями|отфильтр|топ\s*\d|(?:включи|покажи|с\s+)[\s\S]{0,50}(?:названи|статус|сумм|дат|количеств)/iu', $message) === 1);
    }
}
