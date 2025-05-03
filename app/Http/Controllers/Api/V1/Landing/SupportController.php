<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\Support\SupportService; // Уточните реальный путь
use App\Http\Requests\Api\V1\Landing\Support\StoreSupportRequest; // Уточните реальный путь
use App\Http\Responses\Api\V1\SuccessCreationResponse;
use App\Http\Responses\Api\V1\ErrorResponse;
use Illuminate\Contracts\Support\Responsable; // Изменяем тип возврата
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response; // Для кодов состояния

class SupportController extends Controller
{
    protected SupportService $supportService;

    public function __construct(SupportService $supportService)
    {
        $this->supportService = $supportService;
    }

    /**
     * Отправить запрос в техподдержку.
     * POST /api/v1/landing/support
     */
    public function store(StoreSupportRequest $request): Responsable // Используем Responsable
    {
        $userId = Auth::id();
        $data = $request->validated();

        try {
            $success = $this->supportService->createTicket($userId, $data['subject'], $data['message']);
            if (!$success) {
                // Если сервис вернул false, но не выбросил исключение
                return new ErrorResponse(
                    message: 'Failed to create support request',
                    statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
                );
            }
        } catch (\Exception $e) {
            // Логирование ошибки ($e->getMessage())
            report($e);
            return new ErrorResponse(
                message: 'An internal error occurred while creating the support request',
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Возвращаем успешный ответ с кодом 201
        return new SuccessCreationResponse(
            message: 'Support request sent successfully'
        );
    }
} 