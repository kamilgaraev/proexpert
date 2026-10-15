<?php

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use App\BusinessModules\Core\Payments\Support\PaymentDocumentUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PaymentDocument $document
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Платеж утвержден')
            ->success()
            ->greeting('Здравствуйте!')
            ->line("Платежный документ №{$this->document->document_number} утвержден.")
            ->line("Сумма: {$this->document->formatted_amount}")
            ->line("Получатель: {$this->document->getPayeeName()}")
            ->action('Перейти к документу', PaymentDocumentUrl::forAdmin($this->document->id))
            ->line($this->document->requiresActInvoiceFinancialApproval()
                ? 'Финансовое согласование завершено. Запланируйте дату оплаты документа.'
                : 'Документ готов к оплате.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_approved',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'amount' => $this->document->amount,
            'currency' => $this->document->currency,
            'payee_name' => $this->document->getPayeeName(),
            'url' => PaymentDocumentUrl::forAdmin($this->document->id),
        ];
    }
}

