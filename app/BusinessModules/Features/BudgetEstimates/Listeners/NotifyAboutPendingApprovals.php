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

        $approvers = User::where('current_organization_id', $journal->organization_id)
            ->whereHas('roleAssignments', function ($query) use ($journal) {
                $query->active()
                    ->whereHas('context', function ($q) use ($journal) {
                        $q->where('resource_id', $journal->organization_id)
                          ->where('type', 'organization');
                    });
            })
            ->where('id', '!=', $entry->created_by_user_id)
            ->get()
            ->filter(function ($user) {
                return $user->can('construction-journal.approve');
            });

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new \App\Notifications\Journal\JournalEntryPendingApprovalNotification($entry));
    }
}

