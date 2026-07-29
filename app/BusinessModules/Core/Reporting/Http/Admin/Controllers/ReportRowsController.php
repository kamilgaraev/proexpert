<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Reporting\Http\Admin\Controllers;

use App\BusinessModules\Core\Reporting\Application\Access\ReportHttpAuthorizationOrchestrator;
use App\BusinessModules\Core\Reporting\Application\Contracts\GetReportRowsAction;
use App\BusinessModules\Core\Reporting\Http\Admin\Requests\GetReportRowsRequest;
use App\BusinessModules\Core\Reporting\Http\Admin\Resources\ReportRowsResource;
use App\Http\Responses\AdminResponse;
use Illuminate\Http\JsonResponse;

final readonly class ReportRowsController
{
    public function __construct(
        private ReportHttpAuthorizationOrchestrator $authorization,
        private GetReportRowsAction $get,
    ) {}

    public function __invoke(GetReportRowsRequest $request): JsonResponse
    {
        $authorization = $this->authorization->rows($request, $request->runId());
        $page = $this->get->handle(
            $authorization['context'],
            $request->routeId(),
            $request->toWindow(),
        );

        return AdminResponse::success(new ReportRowsResource($page), null, 200, [
            'limit' => $page->limit,
            'next_cursor' => $page->nextCursor,
            'has_more' => $page->hasMore,
            'sort' => [
                'field' => $page->sort->field,
                'direction' => $page->sort->direction->value,
            ],
        ]);
    }
}
