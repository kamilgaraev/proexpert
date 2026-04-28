<?php

declare(strict_types=1);

namespace App\BusinessModules\Features\BudgetEstimates\Services;

use App\BusinessModules\Features\ContractManagement\Services\ContractEstimateService;
use App\Models\ConstructionJournal;
use App\Models\Contract;
use App\Models\ContractEstimateItem;
use App\Models\EstimateItem;
use Illuminate\Support\Collection;

class JournalContractCoverageService
{
    public const STATUS_COVERED = 'covered';
    public const STATUS_AUTO_ATTACH_AVAILABLE = 'auto_attach_available';
    public const STATUS_AMBIGUOUS = 'ambiguous';
    public const STATUS_MISSING_CONTRACT = 'missing_contract';
    public const STATUS_NOT_COVERED = 'not_covered';

    public function __construct(
        private readonly ContractEstimateService $contractEstimateService,
        private readonly EstimateCacheService $estimateCacheService,
    ) {
    }

    public function resolve(?ConstructionJournal $journal, ?EstimateItem $estimateItem): array
    {
        if (!$estimateItem) {
            return [
                'contract_coverage_status' => self::STATUS_MISSING_CONTRACT,
                'contract_id' => null,
                'contract_number' => null,
                'contractor_name' => null,
                'can_auto_attach_contract_coverage' => false,
            ];
        }

        $estimateItem->loadMissing('contractLinks.contract.contractor');

        $contractLink = $this->resolveContractLink($journal?->contract_id, $estimateItem->contractLinks);

        if ($contractLink) {
            return [
                'contract_coverage_status' => self::STATUS_COVERED,
                'contract_id' => $contractLink->contract_id,
                'contract_number' => $contractLink->contract?->number,
                'contractor_name' => $contractLink->contract?->contractor?->name,
                'can_auto_attach_contract_coverage' => false,
            ];
        }

        if ($journal?->contract_id) {
            $journal->loadMissing('contract.contractor');

            return [
                'contract_coverage_status' => self::STATUS_AUTO_ATTACH_AVAILABLE,
                'contract_id' => $journal->contract_id,
                'contract_number' => $journal->contract?->number,
                'contractor_name' => $journal->contract?->contractor?->name,
                'can_auto_attach_contract_coverage' => true,
            ];
        }

        if ($estimateItem->contractLinks->count() > 1) {
            return [
                'contract_coverage_status' => self::STATUS_AMBIGUOUS,
                'contract_id' => null,
                'contract_number' => null,
                'contractor_name' => null,
                'can_auto_attach_contract_coverage' => false,
            ];
        }

        return [
            'contract_coverage_status' => self::STATUS_NOT_COVERED,
            'contract_id' => null,
            'contract_number' => null,
            'contractor_name' => null,
            'can_auto_attach_contract_coverage' => false,
        ];
    }

    public function ensureCoverage(ConstructionJournal $journal, EstimateItem $estimateItem): ?ContractEstimateItem
    {
        if (!$journal->contract_id) {
            return null;
        }

        $estimateItem->loadMissing(['estimate', 'contractLinks.contract']);

        $existing = $this->resolveContractLink($journal->contract_id, $estimateItem->contractLinks);
        if ($existing) {
            return $existing;
        }

        $contract = Contract::query()->find($journal->contract_id);
        if (!$contract || !$estimateItem->estimate) {
            return null;
        }

        $attached = $this->contractEstimateService->attachItems($contract, $estimateItem->estimate, [$estimateItem->id]);
        $this->estimateCacheService->invalidateStructure($estimateItem->estimate);

        return $attached
            ->firstWhere('estimate_item_id', $estimateItem->id)
            ?->load('contract.contractor');
    }

    public function resolveContractLink(?int $journalContractId, Collection $links): ?ContractEstimateItem
    {
        if ($journalContractId) {
            return $links
                ->first(fn (ContractEstimateItem $link): bool => (int) $link->contract_id === $journalContractId);
        }

        return $links->count() === 1 ? $links->first() : null;
    }
}
