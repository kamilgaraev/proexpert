<?php

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentRejectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PaymentDocument $document,
        public string $reason
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Платеж отклонен')
            ->error()
            ->greeting('Здравствуйте!')
            ->line("Платежный документ №{$this->document->document_number} отклонен.")
            ->line("Причина: {$this->reason}")
            ->line("Сумма: {$this->document->formatted_amount}")
            ->line("Получатель: {$this->document->getPayeeName()}")
            ->action('Перейти к документу', url("/admin/payments/documents/{$this->document->id}"))
            ->line('Пожалуйста, исправьте документ и отправьте на повторное рассмотрение.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_rejected',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'reason' => $this->reason,
            'amount' => $this->document->amount,
            'currency' => $this->document->currency,
            'payee_name' => $this->document->getPayeeName(),
            'url' => url("/admin/payments/documents/{$this->document->id}"),
        ];
    }
}

