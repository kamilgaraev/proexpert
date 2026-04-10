<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved;
use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryRejected;
use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted;
use App\BusinessModules\Features\BudgetEstimates\Services\Integration\JournalScheduleIntegrationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\ConstructionJournalEntry;
use App\Models\User;
use App\Notifications\Journal\JournalEntryApprovedNotification;
use App\Notifications\Journal\JournalEntryRejectedNotification;
use App\Services\CompletedWork\CompletedWorkFactService;
use DomainException;
use Illuminate\Support\Facades\DB;

class JournalApprovalService
{
    public function __construct(
        private readonly JournalScheduleIntegrationService $journalScheduleIntegrationService,
        private readonly CompletedWorkFactService $completedWorkFactService
    ) {
    }

    public function submitForApproval(ConstructionJournalEntry $entry): ConstructionJournalEntry
    {
        if (!$entry->status->canSubmit()) {
            throw new DomainException(trans_message('construction_journal.errors.submit_invalid_status'));
        }

        $this->validateEntryForSubmission($entry);

        $entry->submit();
        $this->completedWorkFactService->syncFromJournalEntry($entry->fresh([
            'journal',
            'scheduleTask.estimateItem.contractLinks.contract',
            'workVolumes.estimateItem.contractLinks.contract',
            'workVolumes.workType',
        ]));

        event(new JournalEntrySubmitted($entry));

        return $entry->fresh();
    }

    public function approve(ConstructionJournalEntry $entry, User $approver): ConstructionJournalEntry
    {
        if (!$entry->status->canApprove()) {
            throw new DomainException(trans_message('construction_journal.errors.approve_invalid_status'));
        }

        if (!$this->canApprove($approver, $entry)) {
            throw new DomainException(trans_message('construction_journal.errors.approve_forbidden'));
        }

        return DB::transaction(function () use ($entry, $approver): ConstructionJournalEntry {
            $entry->approve($approver);
            $this->completedWorkFactService->syncFromJournalEntry($entry->fresh([
                'journal',
                'scheduleTask.estimateItem.contractLinks.contract',
                'workVolumes.estimateItem.contractLinks.contract',
                'workVolumes.workType',
            ]));
            $this->journalScheduleIntegrationService->updateTaskProgressFromEntry($entry->fresh(['scheduleTask.estimateItem', 'workVolumes']));

            event(new JournalEntryApproved($entry));

            if ($entry->createdBy) {
                $entry->createdBy->notify(new JournalEntryApprovedNotification($entry));
            }

            return $entry->fresh(['approvedBy', 'scheduleTask', 'createdBy']);
        });
    }

    public function reject(ConstructionJournalEntry $entry, User $approver, string $reason): ConstructionJournalEntry
    {
        if (!$entry->status->canReject()) {
            throw new DomainException(trans_message('construction_journal.errors.reject_invalid_status'));
        }

        if (!$this->canApprove($approver, $entry)) {
            throw new DomainException(trans_message('construction_journal.errors.reject_forbidden'));
        }

        if (trim($reason) === '') {
            throw new DomainException(trans_message('construction_journal.errors.reject_reason_required'));
        }

        return DB::transaction(function () use ($entry, $approver, $reason): ConstructionJournalEntry {
            $entry->reject($approver, $reason);
            $this->completedWorkFactService->syncFromJournalEntry($entry->fresh([
                'journal',
                'scheduleTask.estimateItem.contractLinks.contract',
                'workVolumes.estimateItem.contractLinks.contract',
                'workVolumes.workType',
            ]));

            event(new JournalEntryRejected($entry, $reason));

            if ($entry->createdBy) {
                $entry->createdBy->notify(new JournalEntryRejectedNotification($entry, $reason));
            }

            return $entry->fresh(['approvedBy', 'createdBy']);
        });
    }

    public function canApprove(User $user, ConstructionJournalEntry $entry): bool
    {
        if ($entry->created_by_user_id === $user->id) {
            return false;
        }

        $journal = $entry->journal;
        if (!$journal || $journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        return $user->can('construction-journal.approve');
    }

    public function getApprovalStats(User $user): array
    {
        $journal = $user->current_organization_id
            ? \App\Models\ConstructionJournal::where('organization_id', $user->current_organization_id)->first()
            : null;

        if (!$journal) {
            return [
                'pending_count' => 0,
                'approved_today' => 0,
                'rejected_today' => 0,
            ];
        }

        $pendingCount = \App\Models\ConstructionJournalEntry::where('journal_id', $journal->id)
            ->where('status', JournalEntryStatusEnum::SUBMITTED)
            ->count();

        $approvedToday = \App\Models\ConstructionJournalEntry::where('journal_id', $journal->id)
            ->where('status', JournalEntryStatusEnum::APPROVED)
            ->whereDate('approved_at', today())
            ->count();

        $rejectedToday = \App\Models\ConstructionJournalEntry::where('journal_id', $journal->id)
            ->where('status', JournalEntryStatusEnum::REJECTED)
            ->whereDate('approved_at', today())
            ->count();

        return [
            'pending_count' => $pendingCount,
            'approved_today' => $approvedToday,
            'rejected_today' => $rejectedToday,
        ];
    }

    protected function validateEntryForSubmission(ConstructionJournalEntry $entry): void
    {
        $errors = [];

        if (trim((string) $entry->work_description) === '') {
            $errors[] = trans_message('construction_journal.errors.validation_work_description');
        }

        if (!$entry->entry_date) {
            $errors[] = trans_message('construction_journal.errors.validation_entry_date');
        }

        if ($entry->workVolumes()->count() === 0) {
            $errors[] = trans_message('construction_journal.errors.validation_work_volumes');
        }

        if ($errors !== []) {
            throw new DomainException(
                trans_message('construction_journal.errors.submit_validation_prefix') . ': ' . implode('; ', $errors)
            );
        }
    }
}
