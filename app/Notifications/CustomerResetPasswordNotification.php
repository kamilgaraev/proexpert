<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerResetPasswordNotification extends Notification
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
            ->subject('Восстановление доступа к кабинету заказчика ProHelper')
            ->greeting('Здравствуйте!')
            ->line('Мы получили запрос на смену пароля для кабинета заказчика.')
            ->action('Сменить пароль', $this->url)
            ->line('Если вы не запрашивали смену пароля, просто проигнорируйте это письмо.');
    }
}
