<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
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
        private ReportHttpAuthorizationOrchestrator $authorization,
        private CreateReportRunAction $create,
        private GetReportRunAction $get,
        private RetryReportRunAction $retryAction,
        private CancelReportRunAction $cancelAction,
    ) {}

    public function store(CreateReportRunRequest $request): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $authorization = $this->authorization->createRun($request, $request->reportCode());
        $run = $this->create->handle(
            $authorization['context'],
            $request->toData(),
            $key,
        );

        return AdminResponse::success(new ReportRunResource($run), null, $run->httpStatus)
            ->withHeaders($run->responseHeaders());
    }

    public function show(ReportRunRouteRequest $request): JsonResponse
    {
        $authorization = $this->authorization->showRun($request, $request->runId());
        $run = $this->get->handle($authorization['context'], $request->runId());

        return AdminResponse::success(new ReportRunResource($run));
    }

    public function retry(ReportRunRouteRequest $request): JsonResponse
    {
        $key = new IdempotencyKey((string) $request->header('Idempotency-Key'));
        $authorization = $this->authorization->retryRun($request, $request->runId());
        $run = $this->retryAction->handle($authorization['context'], $request->runId(), $key);

        return AdminResponse::success(new ReportRunResource($run), null, $run->httpStatus)
            ->withHeaders($run->responseHeaders());
    }

    public function cancel(ReportRunRouteRequest $request): JsonResponse
    {
        $authorization = $this->authorization->cancelRun($request, $request->runId());
        $run = $this->cancelAction->handle($authorization['context'], $request->runId());

        return AdminResponse::success(new ReportRunResource($run), null, $run->httpStatus)
            ->withHeaders($run->responseHeaders());
    }
}
