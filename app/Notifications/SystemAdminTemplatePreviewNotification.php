<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SystemAdminTemplatePreviewNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $subject,
        public readonly string $content,
        public readonly string $templateName,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject($this->subject)
            ->line($this->content);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'template_name' => $this->templateName,
            'subject' => $this->subject,
            'content' => $this->content,
        ];
    }
}
