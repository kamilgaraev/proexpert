<?php

namespace App\BusinessModules\Core\Payments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class DailyPaymentDigest extends Notification
{
    use Queueable;

    public function __construct(
        private readonly array $statistics
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        $date = date('d.m.Y');
        
        return (new MailMessage)
            ->subject("Финансовая сводка за {$date}")
            ->markdown('payments::mail.daily-digest', [
                'stats' => $this->statistics,
                'user' => $notifiable
            ]);
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'daily_digest',
            'data' => $this->statistics,
        ];
    }
}

