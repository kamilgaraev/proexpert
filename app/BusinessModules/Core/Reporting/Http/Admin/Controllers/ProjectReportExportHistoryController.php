<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Queries\ProjectReportExportHistory;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ProjectReportExportHistoryRequest;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ProjectReportExportHistoryController
{
    public function __construct(private ReportExecutionContextFactory $contexts, private ProjectReportExportHistory $history) {}

    public function __invoke(ProjectReportExportHistoryRequest $request): JsonResponse
    {
        $facts = $this->contexts->httpFacts($request);
        return AdminResponse::success($this->history->read($facts['actor_id'], $facts['organization_id'],
            (int) $request->attributes->get('report_project_scope_id'), $request->validated('cursor')));
    }
}
