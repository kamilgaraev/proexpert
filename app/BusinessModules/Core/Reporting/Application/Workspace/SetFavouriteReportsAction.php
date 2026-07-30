<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\Workspace;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportWorkspacePreferences;

final readonly class SetFavouriteReportsAction
{
    public function __construct(private ReportWorkspacePreferencesService $service) {}

    public function handle(ReportExecutionContext $context, array $codes): ReportWorkspacePreferences
    {
        return $this->service->setFavourites($context, $codes);
    }
}
