<?php

declare(strict_types=1);

namespace App\Services\Contract;

use App\Models\Contract;

final class ContractContextSourceFields
{
    public static function type(string $field): ?string
    {
        if ($field === 'contract.total_amount') return 'money';
        if (preg_match('/^party_(?:[3-9]|1[0-9]|20)\.(.+)$/D', $field, $extra) === 1) { return self::type('first_party.'.$extra[1]); }
        if ($field === 'contract.date') {
            return 'date';
        }
        if (preg_match('/^(first_party|second_party)\.(.+)$/D', $field, $matches) === 1 && isset(ContractPartyRequisites::LABELS[$matches[2]])) {
            return in_array($matches[2], ['power_of_attorney_date', 'power_of_attorney_valid_until'], true) ? 'date' : 'text';
        }
        if (in_array($field, ['project.name', 'contract.number'], true)
            || preg_match('/^(first_party|second_party)\.(name|legal_name|inn|kpp|ogrn|legal_address|email|phone|role_label)$/D', $field) === 1) {
            return 'text';
        }

        return null;
    }

    public static function forContract(Contract $contract): array
    {
        $rows = $contract->parties()->orderByRaw("CASE side WHEN 'first' THEN 0 WHEN 'second' THEN 1 ELSE 2 END")->orderBy('id')->get();
        $parties = $rows->filter(static fn ($party): bool => $party->getRawOriginal('side') !== null)->keyBy(static fn ($party): string => $party->getRawOriginal('side'));

        return self::withParties([
            'project' => ['name' => $contract->is_multi_project ? ($contract->projects()->orderBy('projects.id')->pluck('projects.name')->implode('; ') ?: null) : $contract->project?->name],
            'contract' => ['number' => $contract->number, 'date' => $contract->date?->toDateString(),
                'total_amount' => $contract->total_amount, 'currency' => strtoupper($contract->currency ?: 'RUB')],
            'first_party' => $parties->has('first') ? ContractPartyRequisites::snapshot($parties->get('first')->toArray()) : [],
            'second_party' => $parties->has('second') ? ContractPartyRequisites::snapshot($parties->get('second')->toArray()) : [],
        ], $rows->map(static fn ($party): array => ContractPartyRequisites::snapshot($party->toArray()))->values()->all());
    }

    public static function withParties(array $context, array $parties): array
    {
        foreach ($parties as $index => $party) {
            if (in_array($party['side'] ?? null, ['first', 'second'], true)) { $context[$party['side'].'_party'] = ContractPartyRequisites::snapshot($party); }
            if ($index >= 2) { $context['party_'.($index + 1)] = ContractPartyRequisites::snapshot($party); }
        }
        return $context;
    }

    public static function value(array $context, string $field): string|array|null
    {
        if (self::type($field) === null) {
            return null;
        }
        [$entity, $attribute] = explode('.', $field, 2);
        $value = $context[$entity][$attribute] ?? null;
        if ($field === 'contract.total_amount') {
            return $value === null ? null : ['amount' => (string) $value, 'currency' => $context['contract']['currency'] ?? 'RUB'];
        }

        return ContractPartyRequisites::display($attribute, $value === null ? null : (string) $value);
    }
}
