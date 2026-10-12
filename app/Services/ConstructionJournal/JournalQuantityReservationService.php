<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\Models\CompletedWork;
use App\Models\ConstructionJournal;
use App\Models\JournalWorkVolume;

final class JournalQuantityReservationService
{
    public function forJournal(ConstructionJournal $journal, ?int $excludedEntryId = null, ?array $estimateItemIds = null): array
    {
        $volumes = JournalWorkVolume::query()
            ->join('construction_journal_entries as entries', 'entries.id', '=', 'journal_work_volumes.journal_entry_id')
            ->join('construction_journals as journals', 'journals.id', '=', 'entries.journal_id')
            ->where('journals.organization_id', $journal->organization_id)->where('journals.project_id', $journal->project_id)
            ->whereNull('journals.deleted_at')->whereNull('entries.deleted_at')
            ->whereIn('entries.status', ['submitted', 'approved'])
            ->whereNotNull('journal_work_volumes.estimate_item_id')
            ->when($excludedEntryId !== null, fn ($query) => $query->where('entries.id', '!=', $excludedEntryId))
            ->when($estimateItemIds !== null, fn ($query) => $query->whereIn('journal_work_volumes.estimate_item_id', $estimateItemIds))
            ->groupBy('journal_work_volumes.estimate_item_id', 'journal_work_volumes.contract_id')
            ->selectRaw('journal_work_volumes.estimate_item_id, journal_work_volumes.contract_id, SUM(journal_work_volumes.quantity) as reserved_quantity')
            ->get();
        $external = CompletedWork::query()->physicalFacts()->confirmed()
            ->where('organization_id', $journal->organization_id)->where('project_id', $journal->project_id)
            ->whereNull('journal_entry_id')->whereNull('journal_work_volume_id')
            ->where(fn ($query) => $query->whereNull('work_origin_type')->orWhere('work_origin_type', '!=', CompletedWork::ORIGIN_JOURNAL))
            ->whereNotNull('estimate_item_id')
            ->when($estimateItemIds !== null, fn ($query) => $query->whereIn('estimate_item_id', $estimateItemIds))
            ->groupBy('estimate_item_id', 'contract_id')
            ->selectRaw('estimate_item_id, contract_id, SUM('.CompletedWork::EFFECTIVE_QUANTITY_SQL.') as reserved_quantity')
            ->get();
        $result = [];
        foreach ($volumes->concat($external) as $row) {
            $itemId = (int) $row->estimate_item_id;
            $result[$itemId] ??= ['total' => 0.0, 'contracts' => []];
            $quantity = (float) $row->reserved_quantity;
            $result[$itemId]['total'] += $quantity;
            if ($row->contract_id !== null) {
                $contractId = (int) $row->contract_id;
                $result[$itemId]['contracts'][$contractId] = ($result[$itemId]['contracts'][$contractId] ?? 0.0) + $quantity;
            }
        }

        return $result;
    }
}
