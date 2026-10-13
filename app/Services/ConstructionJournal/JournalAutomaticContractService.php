<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Enums\ConstructionJournal\JournalEntryStatusEnum;
use App\Models\ConstructionJournalEntry;
use App\Models\ConstructionJournal;
use App\Models\Project;
use App\Models\ContractEstimateItem;
use App\Models\EstimateItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class JournalAutomaticContractService
{
    private const TYPES = ['journal_work_volumes' => 'work-volumes', 'journal_materials' => 'materials', 'journal_equipment' => 'equipment', 'journal_workers' => 'workers'];

    public function resolve(ConstructionJournalEntry $entry, array $input, array $allowedIds, bool $isWork, ?Model $existing = null): ?int
    {
        $item = ! empty($input['estimate_item_id']) ? EstimateItem::query()->find($input['estimate_item_id']) : null;
        if (! $item) {
            return null;
        }
        $ids = ContractEstimateItem::query()->whereIn('contract_id', $allowedIds)
            ->whereIn('estimate_item_id', array_filter([$item->id, $item->parent_work_id]))->distinct()->pluck('contract_id');
        if ($ids->count() !== 1) {
            return null;
        }
        $contractId = (int) $ids->first();
        if ($isWork) {
            $agreed = (float) ContractEstimateItem::query()->where('estimate_item_id', $item->id)->where('contract_id', $contractId)->sum('quantity');
            $reservations = app(JournalQuantityReservationService::class)->forJournal($entry->journal, (int) $entry->id, [(int) $item->id]);
            $reserved = $reservations[$item->id]['contracts'][$contractId] ?? 0.0;
            $own = (float) $entry->workVolumes()->where('estimate_item_id', $item->id)->where('contract_id', $contractId)
                ->when($existing !== null, fn ($query) => $query->where('id', '<>', $existing->id))->sum('quantity');
            if ((float) ($input['quantity'] ?? 0) > max(0.0, $agreed - $reserved - $own) + 0.000001) {
                return null;
            }
        }

        return $contractId;
    }

    public function isAutomatic(Model $row): bool
    {
        $changes = DB::table('journal_contract_assignment_events')->where('journal_entry_id', $row->journal_entry_id)
            ->where('row_type', self::TYPES[$row->getTable()])->where('row_id', $row->id)->orderByDesc('id')->value('changes');
        $changes = is_string($changes) ? json_decode($changes, true, 16, JSON_THROW_ON_ERROR) : $changes;

        return is_array($changes) && ($changes['source'] ?? null) === 'automatic';
    }

    public function record(ConstructionJournalEntry $entry, Model $row, array $input, User $actor, bool $created, ?int $oldContractId): void
    {
        $field = ! app(JournalFieldVisibility::class)->capabilities($actor, $entry->journal->project)['can_manage_contracts'];
        $automatic = ! $created && $this->isAutomatic($row);
        if ($field && ($created || $automatic)) {
            if ($created || $oldContractId !== $row->contract_id) {
                $this->event($entry, $row, $actor, $oldContractId, 'automatic');
            }
        } elseif ($automatic && array_key_exists('contract_id', $input)) {
            $this->event($entry, $row, $actor, $oldContractId, 'manual');
        }
    }

    public function refreshLockedEntry(ConstructionJournalEntry $entry, User $actor): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('journal_contract_refresh_requires_transaction');
        }
        $context = $entry;
        $journal = ConstructionJournal::query()->whereKey($entry->journal_id)->lockForUpdate()->firstOrFail();
        $project = Project::query()->whereKey($journal->project_id)->lockForUpdate()->firstOrFail();
        $journal->setRelation('project', $project);
        $entry = ConstructionJournalEntry::query()->whereKey($entry->id)->where('journal_id', $journal->id)->lockForUpdate()->firstOrFail();
        $entry->setRelation('journal', $journal);
        if ($entry->status === JournalEntryStatusEnum::APPROVED || $entry->isReportingEntry()) {
            return;
        }
        $access = app(ConstructionJournalAccessService::class);
        $allowed = $entry->status === JournalEntryStatusEnum::SUBMITTED
            ? $access->canApprove($actor, $entry)
            : app(\App\Policies\ConstructionJournalEntryPolicy::class)->update($actor, $entry);
        if (! $allowed) {
            throw new AuthorizationException(trans_message('construction_journal.errors.access_denied'));
        }
        $contracts = app(JournalRowContractService::class);
        $allowedIds = $contracts->contracts($entry->journal)->whereIn('status', ['active', 'completed'])->pluck('id')->all();
        foreach (JournalContractAssignmentService::RELATIONS as $type => $relation) {
            foreach ($entry->{$relation}()->orderBy('id')->get() as $row) {
                if (! $this->isAutomatic($row)) {
                    continue;
                }
                $contractId = $this->resolve($entry, $row->toArray(), $allowedIds, $type === 'work-volumes', $row);
                $contracts->assertAssignment($entry->journal, $contractId, $row->estimate_item_id);
                if ($row->contract_id !== $contractId) {
                    $oldContractId = $row->contract_id;
                    $row->update(['contract_id' => $contractId]);
                    $this->event($entry, $row, $actor, $oldContractId, 'automatic');
                }
            }
            $entry->unsetRelation($relation);
            $context->unsetRelation($relation);
        }
    }

    private function event(ConstructionJournalEntry $entry, Model $row, User $actor, ?int $oldContractId, string $source): void
    {
        DB::table('journal_contract_assignment_events')->insert([
            'journal_entry_id' => $entry->id,
            'row_type' => self::TYPES[$row->getTable()],
            'row_id' => $row->id,
            'actor_id' => $actor->id,
            'old_contract_id' => $oldContractId,
            'new_contract_id' => $row->contract_id,
            'reason' => trans_message('construction_journal.messages.contract_'.$source.'_assignment'),
            'changes' => json_encode(['source' => $source, 'old_contract_id' => $oldContractId, 'new_contract_id' => $row->contract_id], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
