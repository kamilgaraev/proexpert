<?php

declare(strict_types=1);

namespace App\Services\ConstructionJournal;

use App\BusinessModules\Features\BudgetEstimates\Services\JournalContractCoverageService;
use App\Models\ConstructionJournal;
use App\Models\ConstructionJournalEntry;
use App\Models\Contract;
use App\Models\EstimateItem;
use App\Models\User;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class JournalRowContractService
{
    public function __construct(private readonly JournalContractCoverageService $coverage) {}

    public function contracts(ConstructionJournal $journal): Builder
    {
        return Contract::query()
            ->where('organization_id', $journal->organization_id)
            ->where(function (Builder $query) use ($journal): void {
                $query->where('project_id', $journal->project_id)
                    ->orWhereHas('projects', static function (Builder $projects) use ($journal): void {
                        $projects->where('projects.id', $journal->project_id);
                    });
            });
    }

    public function resolveForWrite(ConstructionJournalEntry $entry, array $row, ?Model $existing = null, bool $isWorkVolume = true, ?User $actor = null): ?int
    {
        $entry->loadMissing('journal.project');
        $fieldEntry = $actor !== null && ! app(JournalFieldVisibility::class)->capabilities($actor, $entry->journal->project)['can_manage_contracts'];
        if ($fieldEntry) {
            unset($row['contract_id']);
        }
        if (! array_key_exists('contract_id', $row)) {
            if ($existing && ! ($fieldEntry && app(JournalAutomaticContractService::class)->isAutomatic($existing))) {
                if ((int) ($row['estimate_item_id'] ?? 0) !== (int) ($existing->estimate_item_id ?? 0)) {
                    $this->assertAssignment($entry->journal, $existing->contract_id, $row['estimate_item_id'] ?? null);
                }

                return $existing->contract_id === null ? null : (int) $existing->contract_id;
            }

            $entry->loadMissing('journal');
            $allowedIds = $this->contracts($entry->journal)->whereIn('status', ['active', 'completed'])->pluck('id')->all();
            if ($fieldEntry) {
                $contractId = app(JournalAutomaticContractService::class)->resolve($entry, $row, $allowedIds, $isWorkVolume, $existing);
                $this->assertAssignment($entry->journal, $contractId, $row['estimate_item_id'] ?? null);

                return $contractId;
            }
            $defaultId = in_array($entry->journal->contract_id, $allowedIds, true) ? $entry->journal->contract_id : null;
            $item = ! empty($row['estimate_item_id']) ? EstimateItem::query()->find($row['estimate_item_id']) : null;
            if ($item) {
                $item->loadMissing('contractLinks.contract');
                $link = $this->coverage->resolveContractLink($defaultId, $item->contractLinks->whereIn('contract_id', $allowedIds));

                return $link?->contract_id !== null ? (int) $link->contract_id : ($isWorkVolume ? $defaultId : null);
            }

            return $defaultId;
        }

        $contractId = $row['contract_id'] === null ? null : (int) $row['contract_id'];
        $this->assertAssignment($entry->journal, $contractId, $row['estimate_item_id'] ?? null);

        return $contractId;
    }

    public function assertAssignment(ConstructionJournal $journal, ?int $contractId, ?int $estimateItemId): void
    {
        if ($contractId === null) {
            return;
        }

        if ($contractId <= 0 || ! $this->contracts($journal)->whereKey($contractId)
            ->whereIn('status', ['active', 'completed'])->exists()) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_contract'));
        }

        if ($estimateItemId === null) {
            return;
        }

        $item = EstimateItem::query()->whereKey($estimateItemId)
            ->whereHas('estimate', static function (Builder $query) use ($journal): void {
                $query->where('organization_id', $journal->organization_id)->where('project_id', $journal->project_id);
            })->first();
        if (! $item) {
            throw new DomainException(trans_message('construction_journal.errors.invalid_estimate_item'));
        }

        $coveredIds = array_filter([$item->id, $item->parent_work_id]);
        if (! \App\Models\ContractEstimateItem::query()->where('contract_id', $contractId)
            ->whereIn('estimate_item_id', $coveredIds)->exists()) {
            throw new DomainException(trans_message('construction_journal.errors.volume_not_covered_by_contract'));
        }
    }
}
