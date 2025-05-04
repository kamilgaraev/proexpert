<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Log\LogService;
// use Illuminate\Http\Request; // Убираем базовый Request
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as LaravelLog; // Псевдоним для Log
use App\Exceptions\BusinessLogicException;

// Используем созданные FormRequest классы
use App\Http\Requests\Api\V1\Mobile\Log\StoreMaterialUsageRequest;
use App\Http\Requests\Api\V1\Mobile\Log\StoreWorkCompletionRequest;

class LogController extends Controller
{
    protected LogService $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * Сохранить лог использования материала.
     */
    public function storeMaterialUsage(StoreMaterialUsageRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated(); // Используем validated()

        try {
            $logEntry = $this->logService->logMaterialUsage($validatedData, $user);
            // TODO: Возможно, нужен API ресурс для лога
            return response()->json($logEntry, 201);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            // Логируем непредвиденную ошибку
            LaravelLog::error('Error storing material usage log', [
                'user_id' => $user->id,
                'request_data' => $validatedData,
                'exception' => $e
            ]);
            return response()->json(['message' => 'Произошла внутренняя ошибка сервера.'], 500);
        }
    }

    /**
     * Сохранить лог выполнения работы.
     */
    public function storeWorkCompletion(StoreWorkCompletionRequest $request): JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated(); // Используем validated()

        try {
            $logEntry = $this->logService->logWorkCompletion($validatedData, $user);
            // TODO: Возможно, нужен API ресурс для лога
            return response()->json($logEntry, 201);
        } catch (BusinessLogicException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            LaravelLog::error('Error storing work completion log', [
                'user_id' => $user->id,
                'request_data' => $validatedData,
                'exception' => $e
            ]);
            return response()->json(['message' => 'Произошла внутренняя ошибка сервера.'], 500);
        }
    }
}
 