<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\DTOs\RequestUnderstanding\AssistantRequestUnderstanding;

final class AssistantPublishedReportIntent
{
    public static function matches(string $message, AssistantRequestUnderstanding $understanding): bool
    {
        return $understanding->primaryIntent === 'generate_report' && ! $understanding->blocksFileGeneration()
            && preg_match('/принят[а-яё]*\s+(?:выработ|работ|объ[её]м)|динамик[а-яё]*\s+принят|accepted_production_progress|опубликован[а-яё]*\s+отч[её]т/iu', $message) === 1;
    }
}
