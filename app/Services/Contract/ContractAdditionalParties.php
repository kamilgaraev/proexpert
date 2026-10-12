<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Enums\Contract\ContractPartyRoleEnum;
use App\Models\Contract;
use App\Models\Counterparty;
use Illuminate\Validation\ValidationException;

final class ContractAdditionalParties
{
    public static function resolve(int $organizationId, array $input, array $existing): array
    {
        if (!array_is_list($input) || count($input) > 18) { throw ValidationException::withMessages(['additional_parties' => trans_message('contracts.additional_parties_limit')]); }
        $keys = array_map(ContractRevisionParties::key(...), $existing);
        $organizations = array_column($existing, 'linked_organization_id');
        $parties = [];
        foreach ($input as $index => $item) {
            $path = 'additional_parties.'.$index;
            $allowed = ['party_key', 'counterparty_id', 'role_label', 'name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone', 'requisites'];
            if (!is_array($item) || array_diff(array_keys($item), $allowed) !== []) { throw ValidationException::withMessages([$path => trans_message('contracts.additional_party_invalid')]); }
            $key = ContractRevisionParties::key($item);
            if (in_array($key, $keys, true) || in_array($key, ['first', 'second'], true)) { throw ValidationException::withMessages([$path.'.party_key' => trans_message('contracts.additional_party_duplicate')]); }
            $keys[] = $key;
            foreach (array_diff($allowed, ['party_key', 'counterparty_id', 'requisites']) as $field) {
                $limit = match ($field) { 'name', 'email', 'phone' => 255, 'inn' => 32, 'kpp' => 9, 'ogrn' => 64, 'role_label' => 128, default => 512 };
                if (isset($item[$field]) && (!is_string($item[$field]) || mb_strlen($item[$field]) > $limit)) { throw ValidationException::withMessages([$path.'.'.$field => trans_message('contracts.party_text_limit', ['limit' => $limit])]); }
            }
            $base = array_fill_keys(['counterparty_id', 'linked_organization_id', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone'], null);
            if (isset($item['counterparty_id'])) {
                if (!is_int($item['counterparty_id']) || $item['counterparty_id'] < 1) { throw ValidationException::withMessages([$path.'.counterparty_id' => trans_message('contracts.party_counterparty_required')]); }
                $counterparty = Counterparty::query()->where('organization_id', $organizationId)->where('is_active', true)->findOrFail($item['counterparty_id']);
                $base = [...$base, ...$counterparty->only(['name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone', 'linked_organization_id']), 'counterparty_id' => $counterparty->id,
                    ...ContractPartyRequisites::directoryBankDetails($counterparty->bank_details)];
            } else { $base = [...$base, ...array_intersect_key($item, array_flip(['name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone']))]; }
            if (trim($base['name'] ?? '') === '') { throw ValidationException::withMessages([$path.'.name' => trans_message('contracts.party_name_required')]); }
            if ($base['linked_organization_id'] !== null && in_array($base['linked_organization_id'], $organizations, true)) { throw ValidationException::withMessages([$path.'.counterparty_id' => trans_message('contracts.party_organization_duplicate')]); }
            if ($base['linked_organization_id'] !== null) { $organizations[] = $base['linked_organization_id']; }
            if (isset($item['requisites']) && !is_array($item['requisites'])) { throw ValidationException::withMessages([$path.'.requisites' => trans_message('contracts.party_requisites_invalid')]); }
            $requisites = [...array_intersect_key($base, ContractPartyRequisites::LABELS), ...($item['requisites'] ?? [])];
            $parties[] = [...$base, 'party_key' => $key, 'side' => null, 'role' => ContractPartyRoleEnum::OTHER->value,
                'role_label' => trim($item['role_label'] ?? '') ?: 'Участник договора', ...ContractPartyRequisites::validate($requisites, $path.'.requisites')];
        }
        return $parties;
    }

    public static function apply(Contract $contract, int $organizationId, array $input): void
    {
        $extra = self::resolve($organizationId, $input, $contract->parties()->get()->map(static fn ($party): array => ContractPartyRequisites::snapshot($party->toArray()))->all());
        foreach ($extra as $party) {
            $contract->parties()->create([...array_intersect_key($party, array_flip(['party_key', 'side', 'role', 'counterparty_id', 'linked_organization_id', 'name', 'legal_name', 'inn', 'kpp', 'ogrn', 'legal_address', 'email', 'phone'])), 'snapshot' => $party]);
        }
    }
}
