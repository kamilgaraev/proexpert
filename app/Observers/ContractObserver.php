<?php

declare(strict_types=1);

namespace App\Observers;

use App\BusinessModules\Core\MultiOrganization\Reporting\Events\HoldingAllocationFactsProjected;
use App\BusinessModules\Core\MultiOrganization\Reporting\Services\HoldingAllocationFactProjector;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

/**
 * Observer для автоматической синхронизации контракта с Event Sourcing
 */
class ContractObserver
{
    public function __construct(
        private readonly HoldingAllocationFactProjector $holdingAllocationFacts,
    ) {}

    public function retrieved(Contract $contract): void {}

    public function updated(Contract $contract): void
    {
        if (! $contract->wasChanged(['total_amount', 'contractor_id', 'organization_id'])) {
            return;
        }

        $factIds = [];
        foreach ($contract->allocations()->withTrashed()->orderBy('id')->get() as $allocation) {
            $history = $allocation->recordReportingContractVersion();
            $fact = $this->holdingAllocationFacts->recordContractAllocationVersion(
                $contract,
                $allocation,
                $history,
            );
            if ($fact !== null) {
                $factIds[] = (int) $fact->getKey();
            }
        }

        DB::afterCommit(static function () use ($contract, $factIds): void {
            HoldingAllocationFactsProjected::dispatch(
                (int) $contract->organization_id,
                (int) $contract->getKey(),
                $factIds,
            );
        });
    }
}
