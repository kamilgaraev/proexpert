<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\FinancialEvidence;

use App\BusinessModules\Features\AIAssistant\Services\AssistantFactIntentClassifier;

final class AssistantEstimateCompositionIntent
{
    public static function matches(string $query, bool $hasEstimateContext = false): bool
    {
        if (AssistantFactIntentClassifier::isNarrative($query)
            || preg_match('/(?<![\pL\pN])баз[а-яё]*\s+знани/iu', $query)
            || ! ($hasEstimateContext || preg_match('/(?<![\pL\pN])(?:смет|estimate)/iu', $query))) {
            return false;
        }

        $position = (bool) preg_match('/(?<![\pL\pN])(?:позиц|расценк|position|rate)/iu', $query);
        $resources = (bool) preg_match('/(?<![\pL\pN])(?:ресурс|resource)/iu', $query);
        $composition = (bool) preg_match('/(?<![\pL\pN])(?:состав|composition)/iu', $query);

        return ($position && ($resources || $composition)) || ($resources && $composition);
    }
}
