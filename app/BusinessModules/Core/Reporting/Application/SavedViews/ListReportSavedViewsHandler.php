<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewPage;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedViewWindow;

final readonly class ListReportSavedViewsHandler
{
    public function __construct(private ReportSavedViewService $service) {}

    public function handle(ReportExecutionContext $context, ReportSavedViewWindow $window): ReportSavedViewPage
    {
        return $this->service->list($context, $window);
    }
}
