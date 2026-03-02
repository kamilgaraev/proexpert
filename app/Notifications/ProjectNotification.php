<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\Project;
use App\Models\User;

class ProjectNotification extends Notification
{
    use Queueable;

    protected Project $project;
    protected string $messageText;
    protected ?User $sender;

    public function __construct(Project $project, string $messageText, ?User $sender = null)
    {
        $this->project = $project;
        $this->messageText = $messageText;
        $this->sender = $sender;
    }

    public function via($notifiable): array
    {
        // В этом проекте могут быть разные каналы (database, mail, etc.)
        // По умолчанию используем базу данных для уведомлений в интерфейсе.
        return ['database'];
    }

    public function toArray($notifiable): array
    {
        return [
            'project_id' => $this->project->id,
            'project_name' => $this->project->name,
            'message' => $this->messageText,
            'sender_id' => $this->sender?->id,
            'sender_name' => $this->sender?->name,
            'type' => 'project_ai_notification'
        ];
    }
}
