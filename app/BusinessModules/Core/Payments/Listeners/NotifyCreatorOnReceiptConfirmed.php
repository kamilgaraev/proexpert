<?php

namespace App\BusinessModules\Core\Payments\Listeners;

use App\BusinessModules\Core\Payments\Events\PaymentReceiptConfirmed;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Listener для уведомления создателя документа о подтверждении получения получателем
 */
class NotifyCreatorOnReceiptConfirmed implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(PaymentReceiptConfirmed $event): void
    {
        try {
            $document = $event->document;

            // Уведомляем создателя документа (если есть)
            if ($document->created_by_user_id) {
                $creator = User::find($document->created_by_user_id);
                
                if ($creator) {
                    // TODO: Создать Notification класс PaymentReceiptConfirmedNotification
                    // $creator->notify(new PaymentReceiptConfirmedNotification($document, $event->confirmedByUserId));
                    
                    Log::info('payment_recipient.creator_notified', [
                        'document_id' => $document->id,
                        'creator_id' => $creator->id,
                        'confirmed_by_user_id' => $event->confirmedByUserId,
                    ]);
                }
            }

        } catch (\Exception $e) {
            // Не бросаем исключение - отсутствие уведомления не должно ломать систему
            Log::warning('payment_recipient.notify_creator_failed', [
                'document_id' => $event->document->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

