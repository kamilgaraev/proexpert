<?php

declare(strict_types=1);

namespace App\BusinessModules\Core\Payments\Services;

use App\Models\Contract;
use App\Models\ContractPerformanceAct;
use Illuminate\Support\Facades\Log;

final class ContractPaymentPreparationService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function prepare(int $organizationId, array $data): array
    {
        if (isset($data['contract_id'])) {
            $data['source_type'] ??= Contract::class;
            $data['invoiceable_type'] ??= Contract::class;
            foreach (['source', 'invoiceable'] as $reference) {
                if ($data[$reference.'_type'] === Contract::class) {
                    $data[$reference.'_id'] ??= $data['contract_id'];
                }
            }
        }

        $contractIds = isset($data['contract_id']) ? [(int) $data['contract_id']] : [];
        foreach (['invoiceable', 'source'] as $reference) {
            if (($data[$reference.'_type'] ?? null) === Contract::class) {
                $contractIds[] = (int) ($data[$reference.'_id'] ?? 0);
            }
        }
        if (($data['invoiceable_type'] ?? null) === ContractPerformanceAct::class) {
            $actContractId = (int) ContractPerformanceAct::query()
                ->whereKey((int) ($data['invoiceable_id'] ?? 0))->value('contract_id');
            $contractIds[] = $actContractId;
            $data['contract_id'] ??= $actContractId;
        }
        $contractIds = array_values(array_unique($contractIds));
        if (count($contractIds) > 1 || in_array(0, $contractIds, true)) {
            throw new \DomainException(trans_message('payments.validation.invoice_basis_scope_mismatch'));
        }
        $contractId = $contractIds[0] ?? null;
        $isContractRelated = ($data['invoiceable_type'] ?? null) === Contract::class
            || ($data['source_type'] ?? null) === Contract::class
            || isset($data['contract_id']);

        $contract = null;
        if ($isContractRelated && $contractId) {
            $contract = Contract::query()
                ->whereKey($contractId)
                ->where('organization_id', $organizationId)
                ->lockForUpdate()
                ->first();

            if ($contract instanceof Contract) {
                if (isset($data['project_id']) && (int) $data['project_id'] !== (int) $contract->project_id) {
                    throw new \DomainException(trans_message('payments.validation.invoice_basis_scope_mismatch'));
                }
                $data['project_id'] ??= $contract->project_id;
                $data['invoiceable_type'] ??= Contract::class;
                $data['invoiceable_id'] ??= $contract->id;
                $data = $this->applyContractPaymentParties($data, $contract);
            } else {
                throw new \DomainException(trans_message('payments.validation.invoice_basis_scope_mismatch'));
            }
        }

        if (($data['invoice_type'] ?? null) !== 'advance' || ! $isContractRelated || ! $contractId || ! empty($data['amount'])) {
            return $data;
        }

        if (! $contract instanceof Contract) {
            Log::warning('payment_document.store.contract_not_found', [
                'contract_id' => $contractId,
                'organization_id' => $organizationId,
            ]);

            throw new \DomainException(sprintf(
                trans_message('payments.validation.contract_not_found_by_id'),
                $contractId
            ));
        }

        $amount = $this->resolveContractAdvanceAmount($contract);

        if ($amount === null) {
            Log::warning('payment_document.store.cannot_calculate_advance', [
                'contract_id' => $contractId,
                'planned_advance_amount' => $contract->planned_advance_amount,
                'total_amount_with_gp' => $contract->total_amount_with_gp,
                'total_amount' => $contract->total_amount,
                'base_amount' => $contract->base_amount,
                'is_fixed_amount' => $contract->is_fixed_amount,
            ]);

            throw new \DomainException(sprintf(
                trans_message('payments.validation.advance_amount_auto_detect_failed'),
                $contract->number ?? $contractId
            ));
        }

        $data['amount'] = $amount;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyContractPaymentParties(array $data, Contract $contract): array
    {
        return array_merge($data, app(\App\Services\Contract\ContractPaymentPartyResolver::class)->resolve($contract, $data), [
            'currency' => ($data['currency'] ?? '') ?: ($contract->currency ?: config('payments.defaults.currency', 'RUB')),
        ]);
    }

    private function resolveContractAdvanceAmount(Contract $contract): ?float
    {
        if ($contract->planned_advance_amount && $contract->planned_advance_amount > 0) {
            return (float) $contract->planned_advance_amount;
        }

        if ($contract->is_fixed_amount && $contract->total_amount_with_gp !== null && $contract->total_amount_with_gp > 0) {
            return (float) $contract->total_amount_with_gp;
        }

        if ($contract->total_amount && $contract->total_amount > 0) {
            return (float) $contract->total_amount;
        }

        if ($contract->base_amount && $contract->base_amount > 0) {
            return (float) $contract->base_amount;
        }

        return null;
    }

}
