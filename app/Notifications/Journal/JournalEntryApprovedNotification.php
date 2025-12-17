<?php

namespace App\Notifications\Journal;

use App\Models\ConstructionJournalEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class JournalEntryApprovedNotification extends Notification implements ShouldQueue
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
            ->subject('Ваша запись журнала работ утверждена')
            ->greeting('Здравствуйте!')
            ->line("Ваша запись в журнале работ проекта \"{$projectName}\" была утверждена.")
            ->line("**Запись №{$this->entry->entry_number}** от {$this->entry->entry_date->format('d.m.Y')}")
            ->line("**Утвердил:** {$this->entry->approvedBy->name}")
            ->line("**Дата утверждения:** {$this->entry->approved_at->format('d.m.Y H:i')}")
            ->action('Просмотреть запись', url("/admin/construction-journals/{$journal->id}/entries/{$this->entry->id}"))
            ->line('Спасибо за работу!');
    }

    public function toArray($notifiable): array
    {
        return [
            'entry_id' => $this->entry->id,
            'journal_id' => $this->entry->journal_id,
            'entry_number' => $this->entry->entry_number,
            'entry_date' => $this->entry->entry_date->format('Y-m-d'),
            'approved_by_name' => $this->entry->approvedBy->name,
            'approved_at' => $this->entry->approved_at->format('Y-m-d H:i:s'),
            'project_name' => $this->entry->journal->project->name ?? null,
        ];
    }
}

