<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportDrillDownAction;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportDrillDownRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportDrillDownResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportDrillDownController
{
    public function __construct(
        private ReportExecutionContextFactory $contexts,
        private GetReportDrillDownAction $get,
    ) {
    }

    public function __invoke(CreateReportDrillDownRequest $request): JsonResponse
    {
        $result = $this->get->handle(
            $this->contexts->fromHttp($request),
            $request->routeId(),
            $request->toDrillDown(),
        );

        return AdminResponse::success(new ReportDrillDownResource($result));
    }
}
