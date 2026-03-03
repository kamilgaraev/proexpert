<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class PaymentDocumentCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PaymentDocument $document
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_document_created',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'amount' => $this->document->amount,
            'currency' => $this->document->currency,
            'direction' => $this->document->direction?->value,
            'payer_organization_id' => $this->document->payer_organization_id,
            'organization_id' => $this->document->organization_id,
            'created_at' => $this->document->created_at?->toISOString(),
        ];
    }

    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
