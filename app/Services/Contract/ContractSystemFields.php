<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Exceptions\ContractBuilderException;

final class ContractSystemFields
{
    public function catalogue(): array
    {
        $codes = ['contract.number', 'contract.date', 'project.name'];
        foreach (['first_party', 'second_party', ...array_map(static fn (int $number): string => 'party_'.$number, range(3, 20))] as $party) {
            foreach ([...['name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone', 'role_label'], ...array_keys(ContractPartyRequisites::LABELS)] as $field) {
                $codes[] = $party.'.'.$field;
            }
        }

        return array_map(fn (string $code): array => ['code' => $code,
            'title' => $this->title($code),
            'definition' => $this->definition($code)], $codes);
    }

    private function title(string $code): string
    {
        [$entity, $field] = explode('.', $code, 2);
        if ($field === 'role_label') {
            $number = $entity === 'first_party' ? '1' : ($entity === 'second_party' ? '2' : substr($entity, 6));
            return 'Сторона '.$number.' — роль в договоре';
        }
        if (str_starts_with($entity, 'party_')) {
            $label = ContractPartyRequisites::LABELS[$field] ?? trans_message('contract_templates.document_party_fields.'.$field);
            if (in_array($field, ['name', 'legal_name'], true)) { $label = $field === 'name' ? 'Наименование / ФИО' : 'Полное наименование'; }
            return 'Сторона '.substr($entity, 6).' — '.mb_strtolower($label);
        }
        if (isset(ContractPartyRequisites::LABELS[$field]) && in_array($entity, ['first_party', 'second_party'], true)) {
            return ($entity === 'first_party' ? 'Заказчик' : 'Исполнитель').' — '.mb_strtolower(ContractPartyRequisites::LABELS[$field]);
        }
        return trans_message('contract_system_fields.'.str_replace('.', '_', $code));
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
