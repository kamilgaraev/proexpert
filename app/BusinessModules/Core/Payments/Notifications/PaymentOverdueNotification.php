<?php

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PaymentDocument $document,
        public int $overdueDays
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $urgency = $this->overdueDays > 30 ? 'срочно' : ($this->overdueDays > 7 ? 'важно' : '');

        return (new MailMessage)
            ->subject("⚠️ Просроченный платеж - {$this->overdueDays} дн.")
            ->error()
            ->greeting('Внимание!')
            ->line("Платежный документ №{$this->document->document_number} просрочен на {$this->overdueDays} дней!")
            ->line("Получатель: {$this->document->getPayeeName()}")
            ->line("Сумма: {$this->document->formatted_amount}")
            ->line("Срок оплаты был: " . $this->document->due_date?->format('d.m.Y'))
            ->when($urgency, fn($mail) => $mail->line("⚠️ Требуется {$urgency} принять меры!"))
            ->action('Перейти к документу', url("/admin/payments/documents/{$this->document->id}"))
            ->line('Просьба незамедлительно принять меры для погашения задолженности.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_overdue',
            'document_id' => $this->document->id,
            'document_number' => $this->document->document_number,
            'amount' => $this->document->amount,
            'remaining_amount' => $this->document->remaining_amount,
            'currency' => $this->document->currency,
            'payee_name' => $this->document->getPayeeName(),
            'due_date' => $this->document->due_date?->format('Y-m-d'),
            'overdue_days' => $this->overdueDays,
            'urgency' => $this->overdueDays > 30 ? 'critical' : ($this->overdueDays > 7 ? 'high' : 'normal'),
            'url' => url("/admin/payments/documents/{$this->document->id}"),
        ];
    }
}

