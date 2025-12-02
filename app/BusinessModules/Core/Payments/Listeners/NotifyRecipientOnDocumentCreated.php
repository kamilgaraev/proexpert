<?php

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentCreated;
use App\BusinessModules\Core\Payments\Services\PaymentRecipientNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class NotifyRecipientOnDocumentCreated implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly PaymentRecipientNotificationService $notificationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentDocumentCreated $event): void
    {
        try {
            $document = $event->document;

            // Отправляем уведомление получателю (если зарегистрирован)
            // Метод сам проверит, зарегистрирован ли получатель
            $this->notificationService->notifyRecipient($document);

        } catch (\Exception $e) {
            // Не бросаем исключение - отсутствие уведомления не должно ломать систему
            Log::warning('payment_recipient.notify_on_created_failed', [
                'document_id' => $event->document->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

