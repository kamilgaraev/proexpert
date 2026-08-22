<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryApproved;
use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntryRejected;
use App\BusinessModules\Features\BudgetEstimates\Events\JournalEntrySubmitted;
use App\Domain\Authorization\Services\AuthorizationService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Enums\ConstructionJournal\JournalStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\EstimateItem;
use App\Models\JournalEntryApprovalEvent;
use App\Models\JournalWorkVolume;
use App\Models\User;
use App\Notifications\Journal\JournalEntryApprovedNotification;
use App\Notifications\Journal\JournalEntryRejectedNotification;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\Logging\LoggingService;
use App\Services\Workflow\WorkflowGuardService;
use BackedEnum;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class JournalApprovalService
{
    public function __construct(
        private readonly ConstructionJournalService $journalService,
        private readonly CompletedWorkFactService $completedWorkFactService,
        private readonly WorkflowGuardService $workflowGuardService,
        private readonly LoggingService $logging,
        private readonly AuthorizationService $authorizationService,
    ) {}

    public function submitForApproval(ConstructionJournalEntry $entry, ?User $actor = null): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($entry, $actor): ConstructionJournalEntry {
            $entry = $this->lockJournalAndEntry($entry);
            if (! $entry->status->canSubmit()) {
                throw new DomainException(trans_message('construction_journal.errors.submit_invalid_status'));
            }

            $fromStatus = $entry->status;
            $this->validateEntryForSubmission($entry);
            $this->validateWorkflowForSubmission($entry);
            $this->assertEntryQuantitiesAvailable($entry);
            $entry->submit();
            $this->recordApprovalEvent($entry, 'submitted', $fromStatus, $entry->status, $actor);
            $this->completedWorkFactService->syncFromJournalEntry($entry->fresh($this->factRelations()));
            $this->recordEntryAudit('construction_journal_entry.submitted', $entry);
            $result = $entry->fresh();

            DB::afterCommit(static fn () => event(new JournalEntrySubmitted($result)));

            return $result;
        });
    }

    private function validateWorkflowForSubmission(ConstructionJournalEntry $entry): void
    {
        $blockers = $this->workflowGuardService->journalEntryBlockers($entry);
        $hardBlockers = array_values(array_filter(
            $blockers,
            fn (array $blocker): bool => ! ($blocker['can_override'] ?? false)
        ));

        if ($hardBlockers === []) {
            return;
        }

        $messages = array_values(array_unique(array_filter(array_map(
            fn (array $blocker): string => (string) ($blocker['message'] ?? ''),
            $hardBlockers
        ))));

        throw new DomainException(
            trans_message('construction_journal.errors.submit_validation_prefix').': '.implode('; ', $messages)
        );
    }

    public function approve(ConstructionJournalEntry $entry, User $approver, ?array $override = null): ConstructionJournalEntry
    {
        return DB::transaction(function () use ($entry, $approver, $override): ConstructionJournalEntry {
            $entry = $this->lockJournalAndEntry($entry);
            if (! $entry->status->canApprove()) {
                throw new DomainException(trans_message('construction_journal.errors.approve_invalid_status'));
            }
            if (! $this->canApprove($approver, $entry)) {
                throw new DomainException(trans_message('construction_journal.errors.approve_forbidden'));
            }
            $this->workflowGuardService->assertJournalEntryConfirmable(
                $entry,
                $approver,
                $override,
                'journal_approve',
            );
            $this->assertEntryQuantitiesAvailable($entry);

            $fromStatus = $entry->status;
            $this->journalService->commitMaterialConsumption($entry);
            $entry->approve($approver);
            $this->recordApprovalEvent($entry, 'approved', $fromStatus, $entry->status, $approver);
            $this->completedWorkFactService->syncFromJournalEntry($entry->fresh($this->factRelations()));
            $this->recordEntryAudit('construction_journal_entry.approved', $entry, $approver);
            $result = $entry->fresh(['approvedBy', 'scheduleTask', 'createdBy']);
            DB::afterCommit(static function () use ($result): void {
                event(new JournalEntryApproved($result));
                $result->createdBy?->notify(new JournalEntryApprovedNotification($result));
            });

            return $result;
        });
    }

    public function reject(ConstructionJournalEntry $entry, User $approver, string $reason): ConstructionJournalEntry
    {
        if (trim($reason) === '') {
            throw new DomainException(trans_message('construction_journal.errors.reject_reason_required'));
        }

        return DB::transaction(function () use ($entry, $approver, $reason): ConstructionJournalEntry {
            $entry = $this->lockJournalAndEntry($entry);
            if (! $entry->status->canReject()) {
                throw new DomainException(trans_message('construction_journal.errors.reject_invalid_status'));
            }
            if (! $this->canApprove($approver, $entry)) {
                throw new DomainException(trans_message('construction_journal.errors.reject_forbidden'));
            }

            $fromStatus = $entry->status;
            $entry->reject($approver, $reason);
            $this->recordApprovalEvent($entry, 'rejected', $fromStatus, $entry->status, $approver, $reason);
            $this->completedWorkFactService->syncFromJournalEntry($entry->fresh($this->factRelations()));
            $this->recordEntryAudit('construction_journal_entry.rejected', $entry, $approver);
            $result = $entry->fresh(['approvedBy', 'createdBy']);
            DB::afterCommit(static function () use ($result, $reason): void {
                event(new JournalEntryRejected($result, $reason));
                $result->createdBy?->notify(new JournalEntryRejectedNotification($result, $reason));
            });

            return $result;
        });
    }

    private function lockJournalAndEntry(ConstructionJournalEntry $entry): ConstructionJournalEntry
    {
        $journal = ConstructionJournal::query()
            ->whereKey($entry->journal_id)
            ->lockForUpdate()
            ->firstOrFail();
        if ($journal->status !== JournalStatusEnum::ACTIVE) {
            throw new DomainException(trans_message('construction_journal.errors.journal_not_active'));
        }

        return ConstructionJournalEntry::query()
            ->whereKey($entry->getKey())
            ->where('journal_id', $journal->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function factRelations(): array
    {
        return [
            'journal',
            'scheduleTask.estimateItem.contractLinks.contract',
            'workVolumes.estimateItem.contractLinks.contract',
            'workVolumes.workType',
        ];
    }

    private function assertEntryQuantitiesAvailable(ConstructionJournalEntry $entry): void
    {
        $entry->loadMissing('journal.contract', 'workVolumes');
        $requestedByItem = $entry->workVolumes
            ->filter(static fn (JournalWorkVolume $volume): bool => $volume->estimate_item_id !== null)
            ->groupBy('estimate_item_id')
            ->map(static fn ($volumes): float => (float) $volumes->sum('quantity'))
            ->sortKeys();

        foreach ($requestedByItem as $estimateItemId => $requested) {
            $item = EstimateItem::query()
                ->with('contractLinks')
                ->whereKey((int) $estimateItemId)
                ->lockForUpdate()
                ->firstOrFail();
            $planned = (float) ($item->quantity_total ?? $item->quantity ?? 0);
            $contractId = $entry->journal?->contract_id;
            $contractPlanned = $planned;
            if ($contractId) {
                $contractPlanned = (float) $item->contractLinks
                    ->where('contract_id', (int) $contractId)
                    ->sum('quantity');
            }

            $reservedQuery = JournalWorkVolume::query()
                ->where('estimate_item_id', (int) $estimateItemId)
                ->where('journal_entry_id', '!=', $entry->id)
                ->whereHas('journalEntry', static function ($query): void {
                    $query->whereIn('status', [
                        JournalEntryStatusEnum::SUBMITTED,
                        JournalEntryStatusEnum::APPROVED,
                    ]);
                });
            $totalReserved = (float) (clone $reservedQuery)->sum('quantity');
            $contractReserved = $contractId
                ? (float) (clone $reservedQuery)
                    ->whereHas('journalEntry.journal', static function ($query) use ($contractId): void {
                        $query->where('contract_id', (int) $contractId);
                    })
                    ->sum('quantity')
                : $totalReserved;

            $this->assertQuantityAvailable(
                (float) $requested,
                $planned,
                $totalReserved,
                $contractPlanned,
                $contractReserved,
            );
        }
    }

    private function assertQuantityAvailable(
        float $requested,
        float $planned,
        float $reserved,
        float $contractPlanned,
        float $contractReserved,
    ): void {
        $available = $this->remainingQuantity($planned, $reserved, $contractPlanned, $contractReserved);
        if ($planned <= 0 || $contractPlanned <= 0 || $requested - $available > 0.000001) {
            throw new DomainException(trans_message('construction_journal.errors.volume_exceeds_remaining'));
        }
    }

    private function remainingQuantity(
        float $planned,
        float $reserved,
        float $contractPlanned,
        float $contractReserved,
    ): float {
        return min(
            max(0.0, $planned - $reserved),
            max(0.0, $contractPlanned - $contractReserved),
        );
    }

    public function canApprove(User $user, ConstructionJournalEntry $entry): bool
    {
        $journal = $entry->journal;
        if (! $journal || $journal->organization_id !== $user->current_organization_id) {
            return false;
        }

        if ($entry->created_by_user_id === $user->id && ! $this->isOrganizationOwner($user, (int) $journal->organization_id)) {
            return false;
        }

        return $this->authorizationService->can($user, 'construction-journal.approve', [
            'organization_id' => (int) $journal->organization_id,
            'project_id' => (int) $journal->project_id,
        ]);
    }

    private function isOrganizationOwner(User $user, int $organizationId): bool
    {
        return $user->isOrganizationOwner($organizationId)
            || $user->organizations()
                ->where('organization_user.organization_id', $organizationId)
                ->wherePivot('is_owner', true)
                ->wherePivot('is_active', true)
                ->exists();
    }

    public function getApprovalStats(User $user): array
    {
        $journal = $user->current_organization_id
            ? \App\Models\ConstructionJournal::where('organization_id', $user->current_organization_id)->first()
            : null;

        if (! $journal) {
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

        if (! $entry->entry_date) {
            $errors[] = trans_message('construction_journal.errors.validation_entry_date');
        }

        if ((float) $entry->workVolumes()->sum('quantity') <= 0) {
            $errors[] = trans_message('construction_journal.errors.validation_work_volumes');
        }

        if ($errors !== []) {
            throw new DomainException(
                trans_message('construction_journal.errors.submit_validation_prefix').': '.implode('; ', $errors)
            );
        }
    }

    private function recordEntryAudit(string $event, ConstructionJournalEntry $entry, ?User $user = null): void
    {
        $entry->loadMissing('journal');

        $this->logging->audit($event, [
            'organization_id' => $entry->journal?->organization_id,
            'project_id' => $entry->journal?->project_id,
            'journal_id' => $entry->journal_id,
            'journal_name' => $entry->journal?->name,
            'journal_entry_id' => $entry->id,
            'entry_number' => $entry->entry_number,
            'entry_date' => $this->dateValue($entry->entry_date),
            'status' => $this->enumValue($entry->status),
            'performed_by' => $user?->id ?? Auth::id() ?? $entry->created_by_user_id,
        ]);
    }

    private function recordApprovalEvent(
        ConstructionJournalEntry $entry,
        string $event,
        JournalEntryStatusEnum $fromStatus,
        JournalEntryStatusEnum $toStatus,
        ?User $actor = null,
        ?string $reason = null
    ): void {
        $entry->loadMissing('journal');

        JournalEntryApprovalEvent::create([
            'journal_entry_id' => $entry->id,
            'organization_id' => $entry->journal->organization_id,
            'project_id' => $entry->journal->project_id,
            'actor_user_id' => $actor?->id ?? Auth::id() ?? $entry->created_by_user_id,
            'event' => $event,
            'from_status' => $fromStatus->value,
            'to_status' => $toStatus->value,
            'reason' => $reason,
            'occurred_at' => now(),
        ]);
    }

    private function enumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return $value !== null ? (string) $value : null;
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toDateString();
        }

        return is_string($value) ? $value : null;
    }
}
