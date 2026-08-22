<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Listeners;

use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted;
use App\Domain\Authorization\Models\AuthorizationContext;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

class NotifyAboutPendingApprovals implements ShouldQueue
{
    public function __construct(
        private readonly AuthorizationService $authorization,
    ) {}

    public function handle(JournalEntrySubmitted $event): void
    {
        $entry = $event->entry;
        $journal = $entry->journal;

        $approvers = User::query()
            ->whereHas('roleAssignments', function ($query) use ($journal): void {
                $query->active()->whereHas('context', function ($contextQuery) use ($journal): void {
                    $contextQuery
                        ->where(function ($organizationContext) use ($journal): void {
                            $organizationContext
                                ->where('type', AuthorizationContext::TYPE_ORGANIZATION)
                                ->where('resource_id', $journal->organization_id);
                        })
                        ->orWhere(function ($projectContext) use ($journal): void {
                            $projectContext
                                ->where('type', AuthorizationContext::TYPE_PROJECT)
                                ->where('resource_id', $journal->project_id);
                        });
                });
            })
            ->where('id', '!=', $entry->created_by_user_id)
            ->get()
            ->filter(fn (User $user): bool => $this->authorization->can(
                $user,
                'construction-journal.approve',
                [
                    'organization_id' => (int) $journal->organization_id,
                    'project_id' => (int) $journal->project_id,
                ],
            ));

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new \App\Notifications\Journal\JournalEntryPendingApprovalNotification($entry));
    }
}
