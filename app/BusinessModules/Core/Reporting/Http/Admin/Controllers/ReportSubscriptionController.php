<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\CreateReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\DeleteReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ListReportSubscriptionsHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\PauseReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\ResumeReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\RunReportSubscriptionNowHandler;
use App\BusinessModules\Core\Reporting\Application\Subscriptions\UpdateReportSubscriptionHandler;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\CreateReportSubscriptionRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ListReportSubscriptionsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\ReportSubscriptionRouteRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\RunReportSubscriptionNowRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\UpdateReportSubscriptionRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSubscriptionDeliveryResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSubscriptionPageResource;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportSubscriptionResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportSubscriptionController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private ListReportSubscriptionsHandler $list,
        private CreateReportSubscriptionHandler $create,
        private UpdateReportSubscriptionHandler $update,
        private DeleteReportSubscriptionHandler $delete,
        private PauseReportSubscriptionHandler $pause,
        private ResumeReportSubscriptionHandler $resume,
        private RunReportSubscriptionNowHandler $runNow,
    ) {}

    public function index(ListReportSubscriptionsRequest $request): JsonResponse
    {
        return AdminResponse::success(new ReportSubscriptionPageResource(
            $this->list->handle($this->authorization->catalog($request)->context, $request->toWindow()),
        ));
    }

    public function store(CreateReportSubscriptionRequest $request): JsonResponse
    {
        return AdminResponse::success(
            new ReportSubscriptionResource($this->create->handle($this->authorization->catalog($request)->context, $request->toData())),
            code: 201,
        );
    }

    public function update(UpdateReportSubscriptionRequest $request): JsonResponse
    {
        return AdminResponse::success(new ReportSubscriptionResource(
            $this->update->handle($this->authorization->catalog($request)->context, $request->routeId(), $request->toData()),
        ));
    }

    public function destroy(ReportSubscriptionRouteRequest $request): JsonResponse
    {
        $this->delete->handle($this->authorization->catalog($request)->context, $request->routeId());

        return AdminResponse::success(null, code: 204);
    }

    public function pause(ReportSubscriptionRouteRequest $request): JsonResponse
    {
        return AdminResponse::success(new ReportSubscriptionResource(
            $this->pause->handle($this->authorization->catalog($request)->context, $request->routeId()),
        ));
    }

    public function resume(ReportSubscriptionRouteRequest $request): JsonResponse
    {
        return AdminResponse::success(new ReportSubscriptionResource(
            $this->resume->handle($this->authorization->catalog($request)->context, $request->routeId()),
        ));
    }

    public function runNow(RunReportSubscriptionNowRequest $request): JsonResponse
    {
        return AdminResponse::success(
            new ReportSubscriptionDeliveryResource($this->runNow->handle(
                $this->authorization->catalog($request)->context,
                $request->routeId(),
                $request->idempotencyKey(),
            )),
            code: 201,
        );
    }
}
