<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\BusinessModules\Core\Payments\Enums\InvoiceDirection;
use App\Models\Contract;
use App\Models\Contractor;
use DomainException;

final class ContractPaymentPartyResolver
{
    public function __construct(private readonly ContractSideResolverService $sides) {}

    public function resolve(Contract $contract, array $requested = []): array
    {
        $organizationId = (int) $contract->organization_id;
        $sides = $this->sides->resolve($contract, $organizationId);
        $payer = $sides['first_party'];
        $payee = $sides['second_party'];
        if ($payer === null && $payee === null && $contract->contract_side_type === null
            && $contract->firstParty === null && $contract->secondParty === null) {
            $direction = $requested['direction'] ?? InvoiceDirection::OUTGOING;
            $isIncome = $direction === InvoiceDirection::INCOMING || $direction === InvoiceDirection::INCOMING->value;
            $owner = ['organization_id' => $organizationId];
            $other = $contract->contractor === null ? null : [
                'linked_organization_id' => $contract->contractor->source_organization_id,
                'entity_type' => 'contractor',
                'id' => $contract->contractor_id,
            ];
            $payer = $isIncome ? $other : $owner;
            $payee = $isIncome ? $owner : $other;
            $sides['is_income'] = $isIncome;
        }
        $payerOrganizationId = $payer['organization_id'] ?? $payer['linked_organization_id'] ?? null;
        $payeeOrganizationId = $payee['organization_id'] ?? $payee['linked_organization_id'] ?? null;
        if ((int) $payerOrganizationId !== $organizationId && (int) $payeeOrganizationId !== $organizationId) {
            throw new DomainException(trans_message('contracts.payment_party_unresolved'));
        }

        $payerContractorId = $payerOrganizationId ? null : $this->externalContractorId($organizationId, $payer, $requested['payer_contractor_id'] ?? null);
        $payeeContractorId = $payeeOrganizationId ? null : $this->externalContractorId($organizationId, $payee, $requested['payee_contractor_id'] ?? $contract->contractor_id, $contract->contractor_id);

        return [
            'direction' => $sides['is_income'] ? InvoiceDirection::INCOMING : InvoiceDirection::OUTGOING,
            'payer_organization_id' => $payerOrganizationId,
            'payer_contractor_id' => $payerContractorId,
            'payee_organization_id' => $payeeOrganizationId,
            'payee_contractor_id' => $payeeContractorId,
            'contractor_id' => $sides['is_income'] ? $payerContractorId : $payeeContractorId,
        ];
    }

    private function externalContractorId(int $organizationId, ?array $party, mixed $requestedId, ?int $savedContractorId = null): int
    {
        if ($party === null) {
            throw new DomainException(trans_message('contracts.payment_party_unresolved'));
        }
        $query = Contractor::query()->where('organization_id', $organizationId);
        if ($savedContractorId !== null) {
            $query->whereKey($savedContractorId);
        } elseif (($party['entity_type'] ?? null) === 'contractor' && ($party['source'] ?? null) !== 'contract_party_snapshot') {
            $query->whereKey($party['id']);
        } elseif (!empty($party['inn'])) {
            $query->where('inn', $party['inn']);
            if (!empty($party['kpp'])) {
                $query->where('kpp', $party['kpp']);
            }
        } else {
            throw new DomainException(trans_message('contracts.payment_party_unresolved'));
        }
        if ($requestedId !== null) {
            $query->whereKey($requestedId);
        }
        $ids = $query->limit(2)->pluck('id');
        if ($ids->count() !== 1) {
            throw new DomainException(trans_message('contracts.payment_party_unresolved'));
        }

        return (int) $ids->first();
    }
}
