<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Log\LogService;
// use Illuminate\Http\Request; // Убираем базовый Request
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as LaravelLog; // Псевдоним для Log
use App\Exceptions\BusinessLogicException;

// Новые FormRequest классы
use App\Http\Requests\Api\V1\Mobile\Log\StoreMaterialReceiptRequest;
use App\Http\Requests\Api\V1\Mobile\Log\StoreMaterialWriteOffRequest;
use App\Http\Requests\Api\V1\Mobile\Log\StoreWorkCompletionRequest;

// Импортируем ресурсы
use App\Http\Resources\Api\V1\Mobile\Log\MobileMaterialUsageLogResource;
use App\Http\Resources\Api\V1\Mobile\Log\MobileWorkCompletionLogResource;

class LogController extends Controller
{
    protected LogService $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
    }

    /**
     * Сохранить лог приемки материала.
     */
    public function storeMaterialReceipt(StoreMaterialReceiptRequest $request): MobileMaterialUsageLogResource | JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();
        
        try {
            // Сервис должен будет обработать $request->file('photo') если оно есть
            $logEntry = $this->logService->logMaterialReceipt($validatedData, $user, $request->file('photo'));
            // Загружаем связи, которые могут понадобиться ресурсу, если сервис их не загрузил
            $logEntry->load(['project', 'material.measurementUnit', 'user', 'supplier']);
            return new MobileMaterialUsageLogResource($logEntry);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            LaravelLog::error('Error storing material receipt log', [
                'user_id' => $user->id,
                'request_data' => $validatedData,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString() // Добавим trace для отладки
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при сохранении приемки материала.'], 500);
        }
    }

    /**
     * Сохранить лог списания материала.
     */
    public function storeMaterialWriteOff(StoreMaterialWriteOffRequest $request): MobileMaterialUsageLogResource | JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();
        try {
            $logEntry = $this->logService->logMaterialWriteOff($validatedData, $user);
            $logEntry->load(['project', 'material.measurementUnit', 'user', 'workType']);
            return new MobileMaterialUsageLogResource($logEntry);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            LaravelLog::error('Error storing material write-off log', [
                'user_id' => $user->id,
                'request_data' => $validatedData,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при сохранении списания материала.'], 500);
        }
    }

    /**
     * Сохранить лог выполнения работы.
     */
    public function storeWorkCompletion(StoreWorkCompletionRequest $request): MobileWorkCompletionLogResource | JsonResponse
    {
        $user = Auth::user();
        $validatedData = $request->validated();
        try {
            // Сервис должен будет обработать $request->file('photo') если оно есть
            $logEntry = $this->logService->logWorkCompletion($validatedData, $user, $request->file('photo'));
            $logEntry->load(['project', 'workType.measurementUnit', 'user']);
            return new MobileWorkCompletionLogResource($logEntry);
        } catch (BusinessLogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getCode() ?: 400);
        } catch (\Throwable $e) {
            LaravelLog::error('Error storing work completion log', [
                'user_id' => $user->id,
                'request_data' => $validatedData,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString()
            ]);
            return response()->json(['success' => false, 'message' => 'Внутренняя ошибка сервера при сохранении выполнения работы.'], 500);
        }
    }
}
 