<?php

namespace App\Notifications\Journal;

use App\Models\ConstructionJournalEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JournalEntryPendingApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public ConstructionJournalEntry $entry
    ) {}

    public function via($notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $journal = $this->entry->journal;
        $projectName = $journal->project->name ?? 'Проект';
        
        return (new MailMessage)
            ->subject('Новая запись журнала работ ожидает утверждения')
            ->greeting('Здравствуйте!')
            ->line("В журнале работ проекта \"{$projectName}\" создана новая запись, которая ожидает вашего утверждения.")
            ->line("**Запись №{$this->entry->entry_number}** от {$this->entry->entry_date->format('d.m.Y')}")
            ->line("**Описание работ:** {$this->entry->work_description}")
            ->line("**Создал:** {$this->entry->createdBy->name}")
            ->action('Перейти к утверждению', url("/admin/construction-journals/{$journal->id}/entries/{$this->entry->id}"))
            ->line('Спасибо за внимание!');
    }

    public function toArray($notifiable): array
    {
        return [
            'entry_id' => $this->entry->id,
            'journal_id' => $this->entry->journal_id,
            'entry_number' => $this->entry->entry_number,
            'entry_date' => $this->entry->entry_date->format('Y-m-d'),
            'work_description' => $this->entry->work_description,
            'created_by_name' => $this->entry->createdBy->name,
            'project_name' => $this->entry->journal->project->name ?? null,
        ];
    }
}

