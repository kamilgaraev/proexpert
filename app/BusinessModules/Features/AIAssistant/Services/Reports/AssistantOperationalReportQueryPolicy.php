<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Services\Reports;

final readonly class AssistantOperationalReportQueryPolicy
{
    public function projectColumn(string $table, callable $hasColumn): ?string
    {
        if ($table === 'projects' && $hasColumn($table, 'id')) {
            return 'id';
        }

        if ($hasColumn($table, 'project_id')) {
            return 'project_id';
        }

        return null;
    }

    public function shouldApplyPeriod(string $table, ?int $projectId): bool
    {
        return ! ($table === 'projects' && $projectId !== null);
    }
}
