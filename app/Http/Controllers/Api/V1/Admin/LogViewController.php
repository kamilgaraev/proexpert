<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LogViewingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\Api\V1\Admin\Log\MaterialUsageLogResource;
use App\Http\Resources\Api\V1\Admin\Log\WorkCompletionLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LogViewController extends Controller
{
    protected LogViewingService $logViewingService;

    public function __construct(LogViewingService $logViewingService)
    {
        $this->logViewingService = $logViewingService;
        $this->middleware('can:view-operation-logs'); // Применяем Gate
    }

    /**
     * Получить пагинированный список логов использования материалов.
     */
    public function getMaterialLogs(Request $request): AnonymousResourceCollection
    {
        $logs = $this->logViewingService->getMaterialUsageLogs($request);
        return MaterialUsageLogResource::collection($logs);
    }

    /**
     * Получить пагинированный список логов выполнения работ.
     */
    public function getWorkLogs(Request $request): AnonymousResourceCollection
    {
        $logs = $this->logViewingService->getWorkCompletionLogs($request);
        return WorkCompletionLogResource::collection($logs);
    }
} 