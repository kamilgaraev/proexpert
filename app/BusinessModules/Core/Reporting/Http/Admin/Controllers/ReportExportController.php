<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
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
        private ReportHttpAuthorizationOrchestrator $authorization,
        private CreateReportExportAction $create,
        private GetReportExportAction $get,
        private RetryReportExportAction $retryAction,
        private CancelReportExportAction $cancelAction,
        private CreateReportDownloadLinkAction $download,
    ) {}

    public function store(CreateReportExportRequest $request): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $data = $request->toData();
        $authorization = $this->authorization->createExport($request, $request->runId(), $data->format);
        $export = $this->create->handle(
            $authorization['context'],
            $request->routeId(),
            $data,
            $key,
        );

        return AdminResponse::success(new ReportExportResource($export), null, $export->httpStatus)
            ->withHeaders($export->responseHeaders());
    }

    public function show(ReportExportRouteRequest $request): JsonResponse
    {
        $authorization = $this->authorization->showExport($request, $request->exportId());
        $export = $this->get->handle($authorization['context'], $request->exportId());

        return AdminResponse::success(new ReportExportResource($export));
    }

    public function retry(ReportExportRouteRequest $request): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $authorization = $this->authorization->retryExport($request, $request->exportId());
        $export = $this->retryAction->handle($authorization['context'], $request->exportId(), $key);

        return AdminResponse::success(new ReportExportResource($export), null, $export->httpStatus)
            ->withHeaders($export->responseHeaders());
    }

    public function cancel(ReportExportRouteRequest $request): JsonResponse
    {
        $authorization = $this->authorization->cancelExport($request, $request->exportId());
        $export = $this->cancelAction->handle($authorization['context'], $request->exportId());

        return AdminResponse::success(new ReportExportResource($export), null, $export->httpStatus)
            ->withHeaders($export->responseHeaders());
    }

    public function downloadLink(CreateReportDownloadLinkRequest $request): JsonResponse
    {
        $authorization = $this->authorization->download($request, $request->exportId());
        $link = $this->download->handle(
            $authorization['context'],
            $request->toData(),
        );

        return AdminResponse::success(new ReportDownloadLinkResource($link));
    }
}
