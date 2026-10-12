<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\JournalApprovalService;
use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\CompletedWork\CompletedWorkFactService;
use App\Services\Logging\LoggingService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final class JournalContractAssignmentService
{
    public const RELATIONS = ['work-volumes' => 'workVolumes', 'materials' => 'materials', 'equipment' => 'equipment', 'workers' => 'workers'];

    public function __construct(
        private readonly ConstructionJournalAccessService $access,
        private readonly JournalRowContractService $contracts,
        private readonly JournalApprovalService $approval,
        private readonly CompletedWorkFactService $facts,
        private readonly LoggingService $logging,
    ) {}

    public function assign(ConstructionJournal $journal, ConstructionJournalEntry $entry, string $rowType, int $rowId, ?int $contractId, string $reason, User $actor): ConstructionJournalEntry
    {
        $reason = trim($reason);
        if ($reason === '' || ! isset(self::RELATIONS[$rowType])) {
            throw new DomainException(trans_message('construction_journal.errors.contract_assignment_invalid'));
        }

        return DB::transaction(function () use ($journal, $entry, $rowType, $rowId, $contractId, $reason, $actor): ConstructionJournalEntry {
            $journal = ConstructionJournal::query()->whereKey($journal->id)->lockForUpdate()->firstOrFail();
            $project = Project::query()->whereKey($journal->project_id)->lockForUpdate()->firstOrFail();
            $journal->setRelation('project', $project);
            $entry = ConstructionJournalEntry::query()->whereKey($entry->id)->where('journal_id', $journal->id)
                ->lockForUpdate()->firstOrFail();
            $entry->setRelation('journal', $journal);
            if (! $this->access->canApprove($actor, $entry)) {
                throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
            }
            if ($entry->status !== JournalEntryStatusEnum::APPROVED || $entry->isReportingEntry()) {
                throw new DomainException(trans_message('construction_journal.errors.contract_assignment_invalid'));
            }

            $relation = self::RELATIONS[$rowType];
            $row = $entry->{$relation}()->whereKey($rowId)->lockForUpdate()->firstOrFail();
            $oldContractId = $row->contract_id;
            $this->contracts->assertAssignment($journal, $contractId, $row->estimate_item_id);
            if ($oldContractId === $contractId) {
                return $entry;
            }

            $row->contract_id = $contractId;
            $row->save();
            $entry->unsetRelation($relation);
            $this->approval->assertEntryQuantitiesAvailable($entry);
            $fact = $this->facts->syncJournalContractAssignment($entry, $row, $actor);
            $changes = ['completed_work_id' => $fact->id, 'old_contract_id' => $oldContractId, 'new_contract_id' => $contractId];
            DB::table('journal_contract_assignment_events')->insert([
                'journal_entry_id' => $entry->id,
                'row_type' => $rowType,
                'row_id' => $row->id,
                'actor_id' => $actor->id,
                'old_contract_id' => $oldContractId,
                'new_contract_id' => $contractId,
                'reason' => $reason,
                'changes' => json_encode($changes, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $this->logging->audit('construction_journal_row.contract_assigned', [
                'user_id' => $actor->id,
                'organization_id' => $journal->organization_id,
                'project_id' => $journal->project_id,
                'journal_entry_id' => $entry->id,
                'row_type' => $rowType,
                'row_id' => $row->id,
                'reason' => $reason,
                ...$changes,
            ]);

            return $entry->fresh();
        });
    }
}
