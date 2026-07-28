<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportDownloadLinkAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportExportAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportExportAction;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportDownloadLinkRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportExportRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportExportRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportDownloadLinkResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportExportResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportExportController
{
    public function __construct(
        private ReportExecutionContextFactory $contexts,
        private CreateReportExportAction $create,
        private GetReportExportAction $get,
        private RetryReportExportAction $retryAction,
        private CancelReportExportAction $cancelAction,
        private CreateReportDownloadLinkAction $download,
    ) {
    }

    public function store(CreateReportExportRequest $request): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $export = $this->create->handle(
            $this->contexts->fromHttp($request),
            $request->routeId(),
            $request->toData(),
            $key,
        );

        return AdminResponse::success(new ReportExportResource($export), null, $export->httpStatus)
            ->withHeaders($export->responseHeaders());
    }

    public function show(ReportExportRouteRequest $request): JsonResponse
    {
        $export = $this->get->handle($this->contexts->fromHttp($request), $request->routeId());

        return AdminResponse::success(new ReportExportResource($export));
    }

    public function retry(ReportExportRouteRequest $request): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $export = $this->retryAction->handle($this->contexts->fromHttp($request), $request->routeId(), $key);

        return AdminResponse::success(new ReportExportResource($export), null, $export->httpStatus)
            ->withHeaders($export->responseHeaders());
    }

    public function cancel(ReportExportRouteRequest $request): JsonResponse
    {
        $export = $this->cancelAction->handle($this->contexts->fromHttp($request), $request->routeId());

        return AdminResponse::success(new ReportExportResource($export), null, $export->httpStatus)
            ->withHeaders($export->responseHeaders());
    }

    public function downloadLink(CreateReportDownloadLinkRequest $request): JsonResponse
    {
        $link = $this->download->handle(
            $this->contexts->fromHttp($request),
            $request->toData(),
        );

        return AdminResponse::success(new ReportDownloadLinkResource($link));
    }
}
