<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
// use App\Services\Support\SupportService; // Удаляем зависимость
use App\Http\Requests\Api\V1\Landing\Support\StoreSupportRequest; // Уточняем реальный путь
use App\Http\Responses\Api\V1\SuccessResourceResponse; // Правильный класс
use App\Http\Responses\Api\V1\ErrorResponse;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail; // Для отправки почты
use App\Mail\SupportRequestMail; // Потребуется создать этот Mailable
use Symfony\Component\HttpFoundation\Response;
use App\Models\User; // Для информации о пользователе
use Illuminate\Http\Request; // Для базовой валидации, пока нет StoreSupportRequest
use Illuminate\Support\Facades\Log; // Для логирования

class SupportController extends Controller
{
    // Удаляем конструктор и свойство
    // protected SupportService $supportService;
    // public function __construct(SupportService $supportService)
    // {
    //    $this->supportService = $supportService;
    // }

    /**
     * Отправить запрос в техподдержку.
     * POST /api/v1/landing/support/request
     * (Обновил путь согласно openapi_landing.yaml)
     */
    // public function store(StoreSupportRequest $request): Responsable // Используем базовый Request пока
    public function store(Request $request): Responsable
    {
        /** @var User|null $user */
        $user = Auth::user(); // Может быть null, если запрос не требует аутентификации

        // TODO: Создать StoreSupportRequest для валидации
        $validated = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string|max:5000',
            'email' => ['required_without:auth', 'email'], // Email обязателен, если пользователь не аутентифицирован
            'name' => ['required_without:auth', 'string', 'max:255'], // Имя обязательно, если не аутентифицирован
        ]);

        $subject = $validated['subject'];
        $messageContent = $validated['message'];
        $senderName = $user ? $user->name : $validated['name'];
        $senderEmail = $user ? $user->email : $validated['email'];

        try {
            // Адрес получателя поддержки (из конфига или .env)
            $supportEmail = config('mail.support_address', config('mail.from.address'));

            // TODO: Создать App\Mail\SupportRequestMail Mailable
            // Отправка письма
            // Mail::to($supportEmail)->send(new SupportRequestMail($senderName, $senderEmail, $subject, $messageContent));

            // Временная заглушка - логирование вместо отправки
             Log::info('Support Request Received', [
                 'sender_name' => $senderName,
                 'sender_email' => $senderEmail,
                 'subject' => $subject,
                 'message' => $messageContent,
                 'recipient' => $supportEmail
             ]);

            // TODO: Можно также сохранять тикет в базу данных

        } catch (\Exception $e) {
            report($e);
            return new ErrorResponse(
                message: 'An internal error occurred while sending the support request',
                statusCode: Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }

        // Возвращаем успешный ответ 200 OK (или 202 Accepted)
        return new SuccessResourceResponse(
            null,
            message: 'Support request sent successfully'
        );
    }
} 