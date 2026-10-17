<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\AIAssistant\Http\Controllers;

use App\BusinessModules\Features\AIAssistant\Services\Reports\AssistantPublishedReportTasks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssistantPublishedReportController extends AbstractAssistantApiController
{
    public function __construct(private readonly AssistantPublishedReportTasks $tasks) {}

    public function show(Request $request, string $taskId): JsonResponse
    {
        return $this->success($request, $this->tasks->read($taskId, $this->actor($request), $this->lifecycleOrganizationId($request)));
    }
}
