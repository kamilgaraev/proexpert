<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractSystemFields
{
    public function catalogue(): array
    {
        $codes = ['contract.number', 'contract.date', 'project.name'];
        foreach (['first_party', 'second_party'] as $party) {
            foreach (['name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone'] as $field) {
                $codes[] = $party.'.'.$field;
            }
        }

        return array_map(fn (string $code): array => ['code' => $code,
            'title' => trans_message('contract_system_fields.'.str_replace('.', '_', $code)),
            'definition' => $this->definition($code)], $codes);
    }

    public function definition(string $code): array
    {
        $type = ContractContextSourceFields::type($code);
        if ($type === null) {
            throw new ContractBuilderException('contracts.library_input_invalid', 422);
        }

        return ['type' => $type, 'required' => in_array($code, ['contract.number', 'contract.date', 'project.name', 'first_party.name', 'second_party.name'], true),
            'source' => ['kind' => 'contract_context', 'field' => $code],
            'display' => ['group' => trans_message('contract_system_fields.group')]];
    }
}
