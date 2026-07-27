<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportExecutionContextFactory;
use App\BusinessModules\Core\Reporting\Application\Contracts\CancelReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\CreateReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRunAction;
use App\BusinessModules\Core\Reporting\Application\Contracts\RetryReportRunAction;
use App\BusinessModules\Core\Reporting\Domain\ValueObjects\IdempotencyKey;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportRunRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportRunRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportRunResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportRunController
{
    public function __construct(
        private ReportExecutionContextFactory $contexts,
        private CreateReportRunAction $create,
        private GetReportRunAction $get,
        private RetryReportRunAction $retryAction,
        private CancelReportRunAction $cancelAction,
    ) {
    }

    public function store(CreateReportRunRequest $request, string $reportCode): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $run = $this->create->handle(
            $this->contexts->fromHttp($request),
            $request->toData($reportCode),
            $key,
        );

        return AdminResponse::success(new ReportRunResource($run), null, $run->httpStatus)
            ->withHeaders($run->responseHeaders());
    }

    public function show(ReportRunRouteRequest $request): JsonResponse
    {
        $run = $this->get->handle($this->contexts->fromHttp($request), $request->routeId());

        return AdminResponse::success(new ReportRunResource($run));
    }

    public function retry(ReportRunRouteRequest $request): JsonResponse
    {
        $run = $this->retryAction->handle($this->contexts->fromHttp($request), $request->routeId());

        return AdminResponse::success(new ReportRunResource($run), null, $run->httpStatus)
            ->withHeaders($run->responseHeaders());
    }

    public function cancel(ReportRunRouteRequest $request): JsonResponse
    {
        $run = $this->cancelAction->handle($this->contexts->fromHttp($request), $request->routeId());

        return AdminResponse::success(new ReportRunResource($run), null, $run->httpStatus)
            ->withHeaders($run->responseHeaders());
    }
}
