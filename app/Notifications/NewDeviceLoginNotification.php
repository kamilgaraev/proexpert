<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\UserAuthSession;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use function trans_message;

class NewDeviceLoginNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly UserAuthSession $session)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject(trans_message('auth.security_new_device_subject'))
            ->view('emails.new_device_login', [
                'user' => $notifiable,
                'session' => $this->session,
            ]);
    }
}
