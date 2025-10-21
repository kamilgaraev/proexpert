<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Log\LogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log as LaravelLog;
use App\Exceptions\BusinessLogicException;

use App\Http\Requests\Api\V1\Mobile\Log\StoreWorkCompletionRequest;

use App\Http\Resources\Api\V1\Mobile\Log\MobileWorkCompletionLogResource;

class LogController extends Controller
{
    protected LogService $logService;

    public function __construct(LogService $logService)
    {
        $this->logService = $logService;
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
 