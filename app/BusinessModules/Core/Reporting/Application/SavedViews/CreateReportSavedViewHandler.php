<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;
use App\BusinessModules\Core\Reporting\Domain\DTO\ReportSavedView;

final readonly class CreateReportSavedViewHandler
{
    public function __construct(private ReportSavedViewService $service) {}

    public function handle(ReportExecutionContext $context, array $input): ReportSavedView
    {
        return $this->service->create($context, $input);
    }
}
