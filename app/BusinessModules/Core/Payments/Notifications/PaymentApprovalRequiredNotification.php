<?php

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentApprovalRequiredNotification extends Notification implements ShouldQueue
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
            ->subject('Требуется утверждение платежа')
            ->greeting('Здравствуйте!')
            ->line("Платежный документ №{$this->document->document_number} ожидает вашего утверждения.")
            ->line("Сумма: {$this->document->formatted_amount}")
            ->line("Получатель: {$this->document->getPayeeName()}")
            ->line("Назначение: {$this->document->payment_purpose}")
            ->action('Перейти к утверждению', url("/admin/payments/documents/{$this->document->id}"))
            ->line('Пожалуйста, рассмотрите документ в ближайшее время.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_approval_required',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'document_type' => $this->document->document_type->value,
            'amount' => $this->document->amount,
            'currency' => $this->document->currency,
            'payee_name' => $this->document->getPayeeName(),
            'url' => url("/admin/payments/documents/{$this->document->id}"),
        ];
    }
}

