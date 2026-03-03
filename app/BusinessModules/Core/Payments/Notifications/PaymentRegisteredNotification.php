<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Models\PaymentTransaction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class PaymentRegisteredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PaymentDocument $document,
        protected PaymentTransaction $transaction
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_registered',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'transaction_id' => $this->transaction->id,
            'amount' => $this->transaction->amount,
            'currency' => $this->document->currency,
            'transaction_date' => $this->transaction->transaction_date?->toISOString(),
            'payer_organization_id' => $this->document->payer_organization_id,
            'organization_id' => $this->document->organization_id,
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
