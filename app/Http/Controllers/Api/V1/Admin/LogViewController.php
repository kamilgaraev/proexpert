<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\LogViewingService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
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
    }

    /**
     * Получить пагинированный список логов использования материалов.
     * 
     * @deprecated Функциональность больше не поддерживается.
     *             Используйте модуль складского учета.
     */
    public function getMaterialLogs(Request $request): JsonResponse
    {
        throw new BusinessLogicException(
            'Логи использования материалов больше не поддерживаются. Используйте модуль складского учета.',
            410
        );
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

    public function getSystemLogs(Request $request): JsonResponse
    {
        try {
            $logs = $this->logViewingService->getSystemLogs($request);
            return response()->json([
                'success' => true,
                'data' => $logs['data'],
                'pagination' => [
                    'current_page' => $logs['current_page'],
                    'per_page' => $logs['per_page'],
                    'total' => $logs['total'],
                    'last_page' => $logs['last_page'],
                ]
            ]);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            Log::error('[LogViewController@getSystemLogs] Unexpected error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при запросе системных логов.'], 500);
        }
    }
} 