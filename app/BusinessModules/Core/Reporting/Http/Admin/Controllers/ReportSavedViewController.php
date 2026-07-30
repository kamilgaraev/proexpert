<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\SavedViews\CreateReportSavedViewHandler;
use App\BusinessModules\Core\Reporting\Application\SavedViews\DeleteReportSavedViewHandler;
use App\BusinessModules\Core\Reporting\Application\SavedViews\GetReportSavedViewHandler;
use App\BusinessModules\Core\Reporting\Application\SavedViews\ListReportSavedViewsHandler;
use App\BusinessModules\Core\Reporting\Application\SavedViews\SetDefaultReportSavedViewHandler;
use App\BusinessModules\Core\Reporting\Application\SavedViews\UpdateReportSavedViewHandler;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportSavedViewRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ListReportSavedViewsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportSavedViewRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportSavedViewRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSavedViewPageResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSavedViewResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportSavedViewController
{
    public function __construct(private ReportHttpAuthorizationOrchestrator $authorization, private ListReportSavedViewsHandler $list, private CreateReportSavedViewHandler $create, private GetReportSavedViewHandler $get, private UpdateReportSavedViewHandler $update, private DeleteReportSavedViewHandler $delete, private SetDefaultReportSavedViewHandler $default) {}

    public function index(ListReportSavedViewsRequest $r): JsonResponse
    {
        return AdminResponse::success(new ReportSavedViewPageResource($this->list->handle($this->authorization->catalog($r)->context, $r->window())));
    }

    public function store(CreateReportSavedViewRequest $r): JsonResponse
    {
        return AdminResponse::success(new ReportSavedViewResource($this->create->handle($this->authorization->catalog($r)->context, $r->payload())));
    }

    public function show(ReportSavedViewRouteRequest $r): JsonResponse
    {
        return AdminResponse::success(new ReportSavedViewResource($this->get->handle($this->authorization->catalog($r)->context, $r->savedViewId())));
    }

    public function update(UpdateReportSavedViewRequest $r): JsonResponse
    {
        return AdminResponse::success(new ReportSavedViewResource($this->update->handle($this->authorization->catalog($r)->context, $r->savedViewId(), $r->payload())));
    }

    public function destroy(ReportSavedViewRouteRequest $r): JsonResponse
    {
        $this->delete->handle($this->authorization->catalog($r)->context, $r->savedViewId());

        return AdminResponse::success([]);
    }

    public function setDefault(ReportSavedViewRouteRequest $r): JsonResponse
    {
        return AdminResponse::success(new ReportSavedViewResource($this->default->handle($this->authorization->catalog($r)->context, $r->savedViewId())));
    }
}
