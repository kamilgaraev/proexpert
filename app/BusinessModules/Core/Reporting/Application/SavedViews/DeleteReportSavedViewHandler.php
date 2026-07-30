<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Application\SavedViews;

use App\BusinessModules\Core\Reporting\Domain\DTO\ReportExecutionContext;

final readonly class DeleteReportSavedViewHandler
{
    public function __construct(private ReportSavedViewService $service) {}

    public function handle(ReportExecutionContext $context, string $id): void
    {
        $this->service->delete($context, $id);
    }
}
