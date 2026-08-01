<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Workspace\GetReportWorkspaceAction;
use App\BusinessModules\Core\Reporting\Application\Workspace\RecordRecentReportAction;
use App\BusinessModules\Core\Reporting\Application\Workspace\SetFavouriteReportsAction;
use App\BusinessModules\Core\Reporting\Application\Workspace\UpdateReportWorkspacePreferencesAction;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportCatalogRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\RecordRecentReportRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\SetReportWorkspaceFavouritesRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportWorkspacePreferencesRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportWorkspacePreferencesResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportWorkspacePreferencesController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private GetReportWorkspaceAction $get,
        private RecordRecentReportAction $recordRecent,
        private SetFavouriteReportsAction $setFavourites,
        private UpdateReportWorkspacePreferencesAction $updatePreferences,
    ) {}

    public function show(GetReportCatalogRequest $request): JsonResponse
    {
        $workspace = $this->get->handle($this->authorization->catalog($request)->context);

        return AdminResponse::success(new ReportWorkspacePreferencesResource($workspace));
    }

    public function recordRecent(RecordRecentReportRequest $request): JsonResponse
    {
        $workspace = $this->recordRecent->handle(
            $this->authorization->catalog($request)->context,
            $request->reportCode(),
        );

        return AdminResponse::success(new ReportWorkspacePreferencesResource($workspace));
    }

    public function setFavourites(SetReportWorkspaceFavouritesRequest $request): JsonResponse
    {
        $workspace = $this->setFavourites->handle(
            $this->authorization->catalog($request)->context,
            $request->reportCodes(),
        );

        return AdminResponse::success(new ReportWorkspacePreferencesResource($workspace));
    }

    public function updatePreferences(UpdateReportWorkspacePreferencesRequest $request): JsonResponse
    {
        $workspace = $this->updatePreferences->handle(
            $this->authorization->catalog($request)->context,
            $request->display(),
        );

        return AdminResponse::success(new ReportWorkspacePreferencesResource($workspace));
    }
}
