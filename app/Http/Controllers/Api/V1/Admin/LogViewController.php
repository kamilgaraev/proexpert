<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LogViewingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\Api\V1\Admin\Log\MaterialUsageLogResource;
use App\Http\Resources\Api\V1\Admin\Log\WorkCompletionLogResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Exceptions\BusinessLogicException;
use Illuminate\Support\Facades\Log;

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
    public function getMaterialLogs(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $logs = $this->logViewingService->getMaterialUsageLogs($request);
            return MaterialUsageLogResource::collection($logs);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[LogViewController@getMaterialLogs] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                // 'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при запросе логов материалов.'], 500);
        }
    }

    /**
     * Получить пагинированный список логов выполнения работ.
     */
    public function getWorkLogs(Request $request): AnonymousResourceCollection | JsonResponse
    {
        try {
            $logs = $this->logViewingService->getWorkCompletionLogs($request);
            return WorkCompletionLogResource::collection($logs);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[LogViewController@getWorkLogs] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при запросе логов работ.'], 500);
        }
    }
} 