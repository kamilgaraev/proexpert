<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentReceiptConfirmedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PaymentDocument $document,
        protected int $confirmedByUserId
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_receipt_confirmed',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'amount' => $this->document->amount,
            'currency' => $this->document->currency,
            'confirmed_by_user_id' => $this->confirmedByUserId,
            'confirmed_at' => now()->toISOString(),
            'organization_id' => $this->document->organization_id,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
