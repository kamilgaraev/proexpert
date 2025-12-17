<?php

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class NotifyAboutPendingApprovals implements ShouldQueue
{
    /**
     * Handle the event.
     */
    public function handle(JournalEntrySubmitted $event): void
    {
        $entry = $event->entry;
        $journal = $entry->journal;

        // Найти пользователей с правом утверждения
        $approvers = User::where('current_organization_id', $journal->organization_id)
            ->whereHas('roles', function ($query) {
                $query->whereHas('permissions', function ($q) {
                    $q->where('name', 'construction-journal.approve');
                });
            })
            ->where('id', '!=', $entry->created_by_user_id) // Исключить создателя
            ->get();

        if ($approvers->isEmpty()) {
            return;
        }

        // Отправить уведомление
        Notification::send($approvers, new \App\Notifications\Journal\JournalEntryPendingApprovalNotification($entry));
    }
}

