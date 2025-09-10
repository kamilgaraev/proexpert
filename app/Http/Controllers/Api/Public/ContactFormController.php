<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\ContactForm;
use App\Services\Notification\TelegramService;
use App\Http\Requests\Api\Public\StoreContactFormRequest;
use App\Http\Resources\Api\Public\ContactFormResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class ContactFormController extends Controller
{
    protected TelegramService $telegramService;

    public function __construct(TelegramService $telegramService)
    {
        $this->telegramService = $telegramService;
    }

    public function store(StoreContactFormRequest $request): JsonResponse
    {
        try {
            $contactForm = ContactForm::create($request->validated());

            $telegramSent = false;
            if (config('telegram.notifications.contact_forms')) {
                $telegramSent = $this->telegramService->sendContactFormNotification($contactForm);
            }

            if ($telegramSent) {
                $contactForm->markAsProcessed();
            }

            Log::info('Contact form submitted', [
                'contact_form_id' => $contactForm->id,
                'email' => $contactForm->email,
                'telegram_sent' => $telegramSent,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Ваша заявка успешно отправлена. Мы свяжемся с вами в ближайшее время.',
                'data' => new ContactFormResource($contactForm),
            ], 201);

        } catch (\Exception $e) {
            Log::error('Contact form submission error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Произошла ошибка при отправке заявки. Попробуйте позже.',
            ], 500);
        }
    }

}
