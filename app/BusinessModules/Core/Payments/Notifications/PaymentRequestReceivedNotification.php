<?php

namespace App\BusinessModules\Core\Payments\Notifications;

use App\BusinessModules\Core\Payments\Models\PaymentDocument;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PaymentRequestReceivedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PaymentDocument $request,
        public string $contractorName
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Получено платежное требование')
            ->greeting('Здравствуйте!')
            ->line("Получено новое платежное требование №{$this->request->document_number}.")
            ->line("От: {$this->contractorName}")
            ->line("Сумма: {$this->request->formatted_amount}")
            ->line("Назначение: {$this->request->payment_purpose}")
            ->line("Срок оплаты: " . $this->request->due_date?->format('d.m.Y'))
            ->action('Рассмотреть требование', url("/admin/payments/requests/{$this->request->id}"))
            ->line('Требование ожидает вашего рассмотрения.');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'payment_request_received',
            'request_id' => $this->request->id,
            'request_number' => $this->request->document_number,
            'contractor_name' => $this->contractorName,
            'amount' => $this->request->amount,
            'currency' => $this->request->currency,
            'due_date' => $this->request->due_date?->format('Y-m-d'),
            'url' => url("/admin/payments/requests/{$this->request->id}"),
        ];
    }
}

