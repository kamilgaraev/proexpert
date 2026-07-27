<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportCatalogAction;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportCatalogRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportCatalogResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportCatalogController
{
    public function __construct(
        private ReportExecutionContextFactory $contexts,
        private GetReportCatalogAction $get,
    ) {
    }

    public function __invoke(GetReportCatalogRequest $request): JsonResponse
    {
        $catalog = $this->get->handle($this->contexts->fromHttp($request));

        return AdminResponse::success(new ReportCatalogResource($catalog));
    }
}
