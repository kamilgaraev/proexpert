<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use App\Services\LegalArchive\CanonicalJson;

final class ContractBuilderLegacyCompatibility
{
    public function snapshot(Contract $contract): array
    {
        $parties = $contract->parties()->orderBy('side')->get();
        if ($contract->getRawOriginal('status') !== 'active' || $contract->project_id === null || $contract->is_multi_project
            || $contract->projects()->count() > 1 || $parties->count() !== 2
            || !in_array($contract->getRawOriginal('contract_side_type'), ['general_contract', 'contract', 'subcontract'], true)
            || $parties->where('linked_organization_id', $contract->organization_id)->count() !== 1) {
            throw new ContractBuilderException('contracts.legacy_adoption_incompatible', 409);
        }
        if (!$contract->stateEvents()->where('event_type', 'created')->exists() && $contract->stateEvents()->where('amount_delta', '!=', 0)->exists()) {
            throw new ContractBuilderException('contracts.revision_history_incompatible', 409);
        }

        return ['contract' => $contract->only(['id', 'organization_id', 'project_id', 'number', 'date', 'contract_side_type', 'status',
            'total_amount', 'currency', 'start_date', 'end_date', 'planned_advance_amount', 'actual_advance_amount',
            'warranty_retention_calculation_type', 'warranty_retention_percentage', 'warranty_retention_coefficient']),
            'parties' => $parties->toArray(),
            'performance_acts' => ['count' => $contract->performanceActs()->count(), 'updated_at' => $contract->performanceActs()->max('updated_at')],
            'payment_documents' => ['count' => $contract->payments()->count(), 'updated_at' => $contract->payments()->max('updated_at')],
            'completed_works' => ['count' => $contract->completedWorks()->count(), 'updated_at' => $contract->completedWorks()->max('updated_at')],
            'specification_ids' => $contract->specifications()->orderBy('specifications.id')->pluck('specifications.id')->all()];
    }

    public function fingerprint(array $baseline): string
    {
        return CanonicalJson::fingerprint($baseline);
    }
}
