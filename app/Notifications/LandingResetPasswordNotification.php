<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use function trans_message;

class LandingResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $url
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(trans_message('auth.password_reset.email.subject'))
            ->greeting(trans_message('auth.password_reset.email.greeting'))
            ->line(trans_message('auth.password_reset.email.intro'))
            ->action(trans_message('auth.password_reset.email.action'), $this->url)
            ->line(trans_message('auth.password_reset.email.outro'));
    }
}
