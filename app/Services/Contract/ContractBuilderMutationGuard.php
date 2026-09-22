<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;
use App\Models\Contract;
use Illuminate\Support\Facades\DB;

final class ContractBuilderMutationGuard
{
    public function assertLegacy(Contract $contract): void
    {
        if (DB::table('contract_builder_instances')->where('contract_id', $contract->id)->exists()) {
            throw new ContractBuilderException('contracts.builder_revision_required', 409);
        }
    }

    public function assertUpdate(Contract $contract, array $attributes, string $event): void
    {
        if ($event === 'revision_activated' || $event === 'supplementary_document_activated'
            || !DB::table('contract_builder_instances')->where('contract_id', $contract->id)->exists()) {
            return;
        }
        $candidate = clone $contract;
        $candidate->forceFill($attributes);
        $protected = ['organization_id', 'project_id', 'contractor_id', 'supplier_id', 'superior_organization_id', 'contract_side_type',
            'number', 'date', 'subject', 'payment_terms', 'delivery_terms', 'base_amount', 'total_amount', 'currency',
            'gp_percentage', 'gp_coefficient', 'gp_calculation_type', 'warranty_retention_calculation_type',
            'warranty_retention_percentage', 'warranty_retention_coefficient', 'planned_advance_amount', 'start_date', 'end_date',
            'is_fixed_amount', 'is_multi_project', 'is_self_execution', 'payment_terms_days', 'advance_payment_percent'];
        if ($candidate->isDirty($protected) || ($contract->getRawOriginal('status') === 'draft' && $candidate->getAttributes()['status'] === 'active')) {
            throw new ContractBuilderException('contracts.builder_revision_required', 409);
        }
    }
}
