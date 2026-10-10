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

        $access = app(\App\Services\ConstructionJournal\ConstructionJournalAccessService::class);
        try {
            $organizationId = $access->approvalOrganizationId($journal);
        } catch (\DomainException) {
            return;
        }
        $approvers = User::query()
            ->whereHas('organizations', fn ($query) => $query->where('organizations.id', $organizationId)
                ->where('organization_user.is_active', true))
            ->get()->filter(function (User $user) use ($access, $entry, $organizationId): bool {
                $user->current_organization_id = $organizationId;
                return $access->canApprove($user, $entry);
            });

        if ($approvers->isEmpty()) {
            return;
        }

        Notification::send($approvers, new \App\Notifications\Journal\JournalEntryPendingApprovalNotification($entry));
    }
}
