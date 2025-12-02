<?php

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentDocumentPaid;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use App\BusinessModules\Core\Payments\Services\PaymentRecipientNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener для уведомления получателя о регистрации платежа
 * 
 * Слушает событие PaymentDocumentPaid и отправляет уведомления получателям
 * Работает только если получатель зарегистрирован в системе
 */
class NotifyRecipientOnPaymentRegistered implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly PaymentRecipientNotificationService $notificationService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(PaymentDocumentPaid $event): void
    {
        try {
            $document = $event->document;

            // Если есть transactionId, загружаем транзакцию
            $transaction = null;
            if ($event->transactionId) {
                $transaction = PaymentTransaction::find($event->transactionId);
            } else {
                // Иначе берем последнюю транзакцию документа
                $transaction = $document->transactions()->latest()->first();
            }

            if (!$transaction) {
                // Если транзакции нет, просто пропускаем уведомление
                return;
            }

            // Отправляем уведомление получателю (если зарегистрирован)
            // Метод сам проверит, зарегистрирован ли получатель
            $this->notificationService->notifyRecipientAboutPayment($document, $transaction);

        } catch (\Exception $e) {
            // Не бросаем исключение - отсутствие уведомления не должно ломать систему
            Log::warning('payment_recipient.notify_on_payment_failed', [
                'document_id' => $event->document->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

