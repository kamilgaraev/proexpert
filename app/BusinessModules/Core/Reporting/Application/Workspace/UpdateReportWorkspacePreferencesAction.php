<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Workspace;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspaceDisplayPreferences;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;

final readonly class UpdateReportWorkspacePreferencesAction
{
    public function __construct(private ReportWorkspacePreferencesService $service) {}

    public function handle(
        ReportExecutionContext $context,
        ReportWorkspaceDisplayPreferences $display,
    ): ReportWorkspacePreferences {
        return $this->service->updateDisplay($context, $display);
    }
}
