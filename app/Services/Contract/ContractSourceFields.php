<?php

declare(strict_types=1);

namespace App\Services\Contract;

final class ContractSourceFields
{
    public static function type(string $entity, string $field): ?string
    {
        return match ($entity.'.'.$field) {
            'organization.name', 'organization.inn', 'organization.kpp', 'organization.ogrn',
            'project.name', 'contract.number', 'counterparty.name', 'counterparty.inn', 'counterparty.kpp',
            'estimate.name', 'estimate.number' => 'text',
            'contract.date', 'contract.start_date', 'contract.end_date', 'estimate.estimate_date' => 'date',
            'contract.total_amount' => 'money',
            'estimate.total_amount' => 'number',
            default => null,
        };
    }
}
