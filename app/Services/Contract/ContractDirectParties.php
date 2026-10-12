<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\DTOs\Contract\ContractPartyData;
use App\Enums\Contract\ContractPartyRoleEnum;
use App\Models\Organization;
use Illuminate\Validation\ValidationException;

final class ContractDirectParties
{
    public static function resolve(int $organizationId, array $input, string $direction): array
    {
        if (!in_array($direction, ['income', 'expense'], true)
            || array_diff(array_keys($input), ['counterparty_id', 'name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone', 'role_label', 'own_role_label', 'requisites']) !== []) {
            throw ValidationException::withMessages(['direct_party' => trans_message('contracts.direct_party_invalid')]);
        }
        foreach (['own_role_label', 'role_label'] as $field) {
            if (isset($input[$field]) && (!is_string($input[$field]) || mb_strlen($input[$field]) > 128)) {
                throw ValidationException::withMessages(['direct_party.'.$field => trans_message('contracts.party_role_label_limit')]);
            }
        }
        $owner = Organization::findOrFail($organizationId);
        $own = new ContractPartyData(role: ContractPartyRoleEnum::OTHER, name: $owner->name, linkedOrganizationId: $organizationId,
            legalName: $owner->legal_name, inn: $owner->tax_number, legalAddress: $owner->address, email: $owner->email, phone: $owner->phone,
            requisites: ['role_label' => trim($input['own_role_label'] ?? '') ?: ($direction === 'expense' ? 'Заказчик' : 'Исполнитель')]);
        $externalInput = $input;
        unset($externalInput['own_role_label']);
        $externalInput['party_key'] = '00000000-0000-4000-8000-000000000001';
        $ownSide = $direction === 'expense' ? 'first' : 'second';
        try { $external = ContractAdditionalParties::resolve($organizationId, [$externalInput], [['side' => $ownSide, 'party_key' => $ownSide, ...$own->snapshot()]])[0]; }
        catch (ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $path => $messages) { $errors[str_replace('additional_parties.0', 'direct_party', $path)] = $messages; }
            throw ValidationException::withMessages($errors);
        }
        $other = new ContractPartyData(role: ContractPartyRoleEnum::OTHER, name: $external['name'],
            counterpartyId: $external['counterparty_id'], linkedOrganizationId: $external['linked_organization_id'],
            legalName: $external['legal_name'], inn: $external['inn'], kpp: $external['kpp'], ogrn: $external['ogrn'],
            legalAddress: $external['legal_address'], email: $external['email'], phone: $external['phone'],
            requisites: [...array_intersect_key($external, ContractPartyRequisites::LABELS),
                'role_label' => trim($input['role_label'] ?? '') ?: ($direction === 'expense' ? 'Исполнитель' : 'Заказчик')]);

        return $direction === 'expense' ? [$own, $other] : [$other, $own];
    }
}
