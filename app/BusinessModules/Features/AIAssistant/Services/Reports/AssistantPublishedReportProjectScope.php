<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

use App\BusinessModules\Features\AIAssistant\Services\AssistantRequestProjectScope;

final class AssistantPublishedReportProjectScope
{
    public static function allows(array $requestPayload, ?int $projectId): bool
    {
        $scope = AssistantRequestProjectScope::fromPlan(['request' => $requestPayload]);
        $message = (string) ($requestPayload['message'] ?? '');
        $organizationWide = AssistantRequestProjectScope::organizationWide($message);
        $current = $scope->requested && AssistantReportEntityQuery::extract($message, 'project') === null;

        return ! (($current && $scope->projectId === null)
            || ($current && $scope->projectId !== null && $projectId !== $scope->projectId)
            || ($organizationWide && $projectId !== null)
            || (! $organizationWide && $projectId === null
                && preg_match('/(?<![\pL\pN])(?:проект(?:а|у|ом|е)?|объект(?:а|у|ом|е)?)(?![\pL\pN])/iu', $message) === 1));
    }
}
